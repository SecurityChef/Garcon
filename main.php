#!/usr/bin/env php
<?php
require './vendor/autoload.php';
require 'symbolTable.php';
require 'taintInfo.php';
require 'utility.php';
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/* initialize parser and traverser */
$parser        = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$traverser     = new NodeTraverser;

/* read and parse code from stdin */

/* for debug */
$code = file_get_contents("demo/new1.php");
// $code = file_get_contents("php://stdin");
$stmts = $parser->parse($code);

/* initialize maps to maintain information */
$tainted_vars = new SymbolTable();
$alias_map = [];
$classes = [];
$classes_assign_map = [];
$user_funcs = []; // a map of user defined functions

/* initialize taint sources */
$sources['input'] = ['_GET'=>1, '_POST'=>1, '_COOKIE'=>1, '_ENV'=>1];
$sources['database'] = ['file_get_contents'=>1, 'mysql_fetch_row'=>1];

/* initialize sinks */
$sinks['sql'] = ['pg_query'=>1, 'mysql_query'=>1, 'mysqli::query'=>1];
$sinks['cmd'] = ['system'=>1];
$sinks['xss'] = ['print_'=>1];

/* initialize sanitizing routine */
$sani_funcs['sql'] = ['escape_sql_string'=>1];
$sani_funcs['cmd'] = ['escapeshellcmd'=>1];
$sani_funcs['xss'] = ['htmlspecialchars'=>1];                 
                   
/* deprecated */
$cond_mode = 0;

/* construct funcs dict */
foreach($stmts as $stmt) {
    if ($stmt instanceof Node\Stmt\Function_) {
        $user_funcs[$stmt->name] = $stmt;
    }
}
do_statements($stmts, $tainted_vars);
pp($code);
//pp($tainted_vars);

fclose($log);

function get_left_side_name($expr) {
    global $classes;
    if ($expr instanceof Node\Expr\Variable) {
        return $expr->name;
    }
    else if ($expr instanceof Node\Expr\ArrayDimFetch) {
        //return $expr->var->name . $expr->dim->value;
        return $expr->var->name;
    }
    else if ($expr instanceof Node\Expr\PropertyFetch) {
        $class_name = $expr->var->name;
        $classes[$class_name] = 1;
        return constr_prop_name($class_name, $expr->name);
    }
    else if ($expr instanceof Node\Arg) {
        return $expr->value->name;
    }
    else if ($expr instanceof Node\Param) {
        return $expr->name;
    }
    else {
        echo "unsupported left side value\n";
        //pp($expr);
    }
}

function deep_copy_assoc_arr($arr) {
    $newarray = [];
    foreach ($arr as $k=>$v) {
        $newarray[$k] = clone $v;
    }
    return $newarray;
}

function union_tables($t1, $t2) {
    pp("union tables");
    pp($t1);
    pp($t2);
    $keys1 = array_keys($t1);
    $keys2 = array_keys($t2);
    $allkeys = array_unique(array_merge($keys1, $keys2));
    $newtable = [];
    foreach($allkeys as $k) {
        $sum = 0;
        if (array_key_exists($k, $t1)) {
            /* if exists in $t1 and $t2 */
            if (array_key_exists($k, $t2)) {
                $t1[$k]->certainty += $t2[$k]->certainty;
                $newtable[$k] = clone $t1[$k];
            }
            /* if only exists in $t1 */
            else {
                $newtable[$k] = $t1[$k];
            }
        }
        /* if only exists in $t2 */
        else {
            $newtable[$k] = $t2[$k];
        }
    }
    //pp($newtable);
    return $newtable;
}

function calc_confidence($cond) {
    if ($cond instanceof Node\Scalar\LNumber) {
        return $cond->value == true;
    }
    else if ($cond instanceof Node\Expr\ConstFetch) {
        $name = $cond->name->parts[0];
        if ($name == "true") {
            return 1;
        }
        else if ($name == "false") {
            return 0;
        }
        else {
            return 1;
        }
    }
    else {
        return 0.5;
    }
    //pp($cond);
}

function augment_table($out, $in, $confidence) {
    foreach ($in as $k=>$v) {
        if (!array_key_exists($k, $out)) {
            $v->certainty *= $confidence;
            $out[$k] = $v;
            pp("add $k, certainty: $v->certainty");
        }
    }
    foreach ($out as $k=>$v) {
        if (!array_key_exists($k, $in)) {
            $v->certainty -= $v->certainty*$confidence;
            pp("update $k, certainty: $v->certainty");
        }
        if ($v->certainty == 0) {
            unset($out[$k]);
            pp("unset $k");
        }
    }
    return $out;
}

function do_assign($left, $right, &$sym_table) {
    global $classes_assign_map, $classes;
    /* special case for simple class assignment */
    if ($right instanceof Node\Expr\Variable) {
        if ($left instanceof Node\Expr\Variable) {
            if (array_key_exists($right->name, $classes)) {
                $classes[$left->name] = 1;
                $classes_assign_map[$left->name] = $right->name;
            }
        }
        else {
            /* ignore for now */
        }
    }
    
    $left_name = get_left_side_name($left);
    $taint_info = eval_expr($right, $sym_table);
    if ($taint_info->value > 0) {
        $left->setAttribute("tainted", clone $taint_info);
        set_var($left,$sym_table);
        pp("set $left_name");
    }
    else {
        $target = get_alias($left_name);
        if (get_var($target, $sym_table)->value != 0) {
            unset($sym_table[$target]);
            pp("unset $left_name");
        }
    }
    return $taint_info;
}

function do_statements($func_stmts, &$sym_table) {
    global $cond_mode;
    /* only consider assign for now */
    foreach ($func_stmts as $stmt) {
        if ($stmt instanceof Node\Expr) {
            pp("process expr...");
            eval_expr($stmt, $sym_table);
        }
        else if ($stmt instanceof Node\Stmt\Function_) {
            pp("process function declaration");
            /* skip declare statement */
            continue;
        }
        /* ignore ifelse for now */
        else if ($stmt instanceof Node\Stmt\If_) {
            // $out_table = $sym_table;
            $inner_table = new SymbolTable();
            /* share a same table but use different confidence */
            $inner_table->table = $sym_table->table;
            $confid = calc_confidence($stmt->cond);
            /* if branch */
            if (is_null($stmt->else)) {
                do_statements($stmt->stmts, $inner_table);
                
            }
            /* else branch */
//            else {
//                if ($confid == 0) {
//                    do_statements($stmt->else->stmts, $sym_table);
//                    $sym_table = augment_table($out_table, $sym_table, $confid);
//                }
//                else {
//                    $table1 = deep_copy_assoc_arr($sym_table);
//                    $table2 = deep_copy_assoc_arr($sym_table);
//                    do_statements($stmt->stmts, $table1);
//
//                    do_statements($stmt->else->stmts, $table2);
//
//                    $sym_table = union_tables($table1, $table2);
//                    pp("out table:");
//                    pp($out_table);
//                    pp("inner:");
//                    pp($sym_table);
//                    $sym_table = augment_table($out_table, $sym_table, $confid);
//                }
//            }
        }
        
        else if ($stmt instanceof Node\Stmt\While_) {
            $confid = calc_confidence($stmt->cond);
            $out_table = $sym_table;
            do_statements($stmt->stmts, $sym_table);
            do_statements($stmt->stmts, $sym_table);
            $sym_table = augment_table($out_table, $sym_table, $confid);
        }
        
        else if ($stmt instanceof Node\Stmt\Return_) {
            pp("process return");
            return eval_expr($stmt->expr, $sym_table);
        }
        
        else {
            //pp("process unsupported statement");
            echo "unsupported statement type ".get_class($stmt)."\n";
        }
        
        //$traverser->traverse(array($stmt));
    }
    //pp($sym_table);
    return new TaintInfo(0, 1);
}

function gen_callee_table($args, $func_proto, $caller_table) {
    $newtable = [];
    $params = $func_proto->params;
    for ($i = 0; $i < count($params); $i++) {
        $left = $params[$i];
        $right = $args[$i];
        $taint_info = eval_expr($right, $caller_table);
        $var_name = get_left_side_name($left);
        if ($taint_info->value > 0) {
            pp("add {$var_name}");
            if ($right instanceof Node\Expr\ArrayDimFetch) {
                $newtable[$var_name] = new TaintInfo($taint_info->value, $taint_info->certainty/2);
            }
            else {
                $newtable[$var_name] = $taint_info;
            }
        }
        else {
            
        }
    }
    return $newtable;
}

function get_alias($name) {
    global $alias_map;
    $target = $name;
    while (array_key_exists($target, $alias_map)) {
        $target = $alias_map[$target];
    }
    pp("var $name's alias is $target...");
    return $target;
}

function resolve_class_assign($name) {
    global $classes_assign_map;
    $target = $name;
    while (array_key_exists($target, $classes_assign_map)) {
        $target = $classes_assign_map[$target];
    }
    pp("class $name's alias is $target...");
    return $target;
}

function get_var($name, $sym_table) {
    $target = get_alias($name);
    if (array_key_exists($target, $sym_table)) {
        return $sym_table[$target];
    }
    else {
        return new TaintInfo(0, 1);
    }    
}

function set_var($left, &$sym_table) {
    $name = get_left_side_name($left);
    $target = get_alias($name);
    $sym_table[$target] = clone $left->getAttribute("tainted");
    
}

function is_sink($func_name) {
    global $sinks;
    if (array_key_exists($func_name, $sinks['sql'])) {
        return 1;
    }
    else if (array_key_exists($func_name, $sinks['cmd'])) {
        return 2;
    }
    else if (array_key_exists($func_name, $sinks['xss'])) {
        return 4;
    }
    else {
        return false;
    }
}

function is_args_tainted($args, $sym_table) {
    foreach($args as $arg) {
        $taint_value = eval_expr($arg, $sym_table);
        if ($taint_value->value != 0) {        
            return $taint_value;
        }
    }
    return new TaintInfo(0, 1);
}

function is_source($name) {
    global $sources;
    if (array_key_exists($name, $sources['input'])) {
        return 1;
    }
    else if (array_key_exists($name, $sources['database'])) {
        return 2;
    }
    else {
        return 0;
    }
}

function is_sanitize($name) {
    global $sani_funcs;
    if (array_key_exists($name, $sani_funcs['sql'])) {
        return 1;
    }
    else if (array_key_exists($name, $sani_funcs['cmd'])) {        
        return 2;
    }
    else {
        return 0;
    }
}

function get_vul_type($source, $sink) {
    if ($source == 1 && ($sink == 1 || $sink == 2)) {
        return -$sink;
    }
    else if ($source == 2 && $sink == 4) {
        return -$sink;
    }
    else {
        return 0;
    }
}

function eval_func($func_name, $args, &$sym_table) {
    global $user_funcs;
    $source_type = is_source($func_name);
    $sink_type = is_sink($func_name);
    if ($sink_type != 0) {
        $taint_info = is_args_tainted($args, $sym_table);
        $vul = get_vul_type($taint_info->value, $sink_type);
        return new TaintInfo($vul, $taint_info->certainty);
    }
    else if ($source_type != 0) {
        return new TaintInfo($source_type, 1);
    }
    /* if user defined function */
    else if (array_key_exists($func_name, $user_funcs)) {
        $func_proto = $user_funcs[$func_name];
        $callee_table = gen_callee_table($args, $func_proto, $sym_table);
        return do_statements($func_proto->stmts, $callee_table);        
    }
    /* if built-in function */
    else {
        // if (is_sanitize($func_name) > 0) {
        //     return new TaintInfo(0, 1);
        // }
        /* special cases */
        if ($func_name == "array_push") {
            $v = eval_expr($args[1], $sym_table);
            $sym_table[get_left_side_name($args[0])] = $v;
            $args[0]->setAttribute("tainted", $v);
            return $v;
        }
        else {
            $info = clone is_args_tainted($args, $sym_table);
            $taint_type = $info->value;
            $sani_type = is_sanitize($func_name);
            
            if ($taint_type > 0) {
                $vul = get_vul_type($taint_type, $sani_type);
                if ($vul != 0) {
                    return new TaintInfo(0, 1);
                }
                else {
                    return $info;
                }
            }
            else {
                return new TaintInfo(0, 1);
            }
        }        
    }
}

function constr_prop_name($class_name, $prop) {
    return "$class_name::$prop";
}

function eval_expr($expr, &$sym_table) {
    global $classes;
    $expr_type = get_class($expr);
    $info = new TaintInfo();

    if ($expr instanceof Node\Expr\Variable) {
        pp("evaluate var {$expr->name}...");
        // $expr->setAttribute("tainted", clone get_var($expr->name, $sym_table));
        $info = clone get_var($expr->name, $sym_table);
    }
    else if ($expr instanceof Node\Scalar\LNumber) {
        pp("evaluate lnumber {$expr->value}...");
        $expr->setAttribute("tainted", new TaintInfo(0, 1));
    }
    else if ($expr instanceof Node\Scalar\String_) {
        pp("evaluate string {$expr->value}...");
        $expr->setAttribute("tainted", new TaintInfo(0, 1));
    }

    else if ($expr instanceof Node\Expr\ArrayDimFetch) {
        pp("evaluate arraydimfetch...");
        //pp($expr);
        /* set certainty to 2 since array fetch will lose half certainty */
        if (is_source($expr->var->name)) {
            $expr->setAttribute("tainted", new TaintInfo(1, 1));
        }
        else {
            $info = eval_expr($expr->var, $sym_table);
            $expr->setAttribute("tainted", new TaintInfo($info->value, $info->certainty/2));
            //pp($expr);
        }
    }

    else if ($expr instanceof Node\Expr\PropertyFetch) {
        pp("evaluate propertyfetch...");
        $resolved_name = resolve_class_assign($expr->var->name);
        pp("resolved class: $resolved_name");
        
        $name = constr_prop_name($resolved_name, $expr->name);
        pp("resolved var: $name");
        // $name = get_left_side_name($expr);
        $classes[$expr->var->name] = 1;
        $expr->setAttribute("tainted", clone get_var($name, $sym_table));
    }

    else if ($expr instanceof Node\Expr\BinaryOp) {
        pp("evaluate binaryOp $expr_type...");
        $left_v = eval_expr($expr->left, $sym_table);
        $right_v = eval_expr($expr->right, $sym_table);
        if ($left_v->value) {
            $expr->setAttribute("tainted", clone $left_v);
        }
        else if ($right_v->value) {
            $expr->setAttribute("tainted", clone $right_v);
        }
        else {
            $expr->setAttribute("tainted", new TaintInfo(0, 1));
        }
    }

    else if ($expr instanceof Node\Scalar\Encapsed) {
        pp("evaluate binaryOp $expr_type...");
        $is_set = false;
        foreach($expr->parts as $part) {
            if (eval_expr($part, $sym_table)) {
                $expr->setAttribute("tainted", new TaintInfo(1, 1));
                $is_set = true;
                break;
            }
        }
        if (!$is_set) {
            $expr->setAttribute("tainted", new TaintInfo(0, 1));
        }
        
    }

    else if ($expr instanceof Node\Scalar\EncapsedStringPart) {
        pp("evaluate EncapsedStringPart $expr->value...");
        $expr->setAttribute("tainted", new TaintInfo(0, 1));
    }

    else if ($expr instanceof Node\Expr\FuncCall) {
        pp("evaluate funcCall {$expr->name->parts[0]}...");
        $func_name = $expr->name->parts[0];
        $v = eval_func($func_name, $expr->args, $sym_table);
        $expr->setAttribute("tainted", clone $v);
    }

    else if ($expr instanceof Node\Expr\MethodCall) {
        $method_name = "{$expr->var->name}::$expr->name";
        pp("evaluate methodCall $method_name...");
        $v = eval_func($method_name, $expr->args, $sym_table);
        $expr->setAttribute("tainted", $v);
    }

    else if ($expr instanceof Node\Arg) {
        pp("evaluate arg $expr_type");
        $v = eval_expr($expr->value, $sym_table);
        $expr->setAttribute("tainted", clone $v);
    }
            
    else if ($expr instanceof Node\Expr\Array_) {
        $expr->setAttribute("tainted", new TaintInfo(0, 1));
    }

    else if ($expr instanceof Node\Expr\Assign) {
        return do_assign($expr->var, $expr->expr, $sym_table);
    }

    else if ($expr instanceof Node\Expr\AssignRef) {
        return do_assignref($expr->var, $expr->expr, $sym_table);
    }
    
    else {
        echo "unsupported expr type: $expr_type\n";
        pp($expr);
    }

    $expr->setAttribute("tainted", $info);
    
    $return_info = $expr->getAttribute("tainted");
    pp("return ".$return_info->value);
    //pp($return_info);
    if ($return_info->value < 0) {
        $percent_certainty = round($return_info->certainty * 100 ) . '%';
        if ($return_info->value == -1) {
            echo "SQL injection vulnerability found in line {$expr->getline()}, certainty: {$percent_certainty}\n";
        }
        else if ($return_info->value == -2) {
            echo "Command line injection vulnerability found in line {$expr->getline()}, certainty: {$percent_certainty}\n";
        }
        else if ($return_info->value == -4) {
            echo "Persistent XSS vulnerability found in line {$expr->getline()}, certainty: {$percent_certainty}\n";
        }
        else {
            echo "Other type of vulnerability found in line {$expr->getline()}, certainty: {$percent_certainty}\n";
        }
    }  
    return $expr->getAttribute("tainted");
}

function do_assignref($left, $right, $sym_table) {
    global $alias_map;
    $alias_map[get_left_side_name($left)] = $right->name;
    return get_var($right->name, $sym_table);
}


?>