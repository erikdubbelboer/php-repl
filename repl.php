#!/usr/bin/php
<?


if (file_exists('./repl-config.php')) {
  include './repl-config.php';
}


function var_dump_object($object) {
  //get_class

  // TODO: print a compact version of the class name and it's properties

  var_dump($object);
}


// This function will insert a return before the last statement.
// It will also add the last ;
function make_command_executable($cmd) {
  $cmd = trim($cmd, " \t;");

  if (strpos($cmd, 'return') !== false) {
    return $cmd . ';';
  }

  // We use the buildin PHP token parser to find the last statement.
  $tokens = token_get_all('<?' . $cmd);

  // The last ;
  $dc = -1;

  foreach ($tokens as $token) {
    if (($token === ';') || (is_array($token) && isset($token[1]) && (strpos($token[1], ';') !== false))) {
      ++$dc;
    }
  }

  // Did the command contain any ;?
  if ($dc == -1) {
    $command = 'return ' . $cmd;
  } else {
    // Keep skipping ; to the $dc'th one
    $p = strpos($cmd, ';');
    for ($i = 0; $i < $dc; ++$i) {
      $p = strpos($cmd, ';', $p + 1);
    }

    // Add a return after the $dc'th ;
    $command = substr($cmd, 0, $p + 1) . 'return ' . substr($cmd, $p + 1);
  }

  return $command . ';';
}


set_error_handler(function($errno, $errstr) {
  echo '! ' . $errstr . "\n";

  return true;
});


// Add auto completion to readline
readline_completion_function(function($input, $index) {
  // Get the full input buffer so we can use some context when suggesting things.
  $info   = readline_info();
  $input  = substr($info['line_buffer'], 0, $info['end']);
  $return = array();

  // Accessing a class method or property
  if (preg_match('/\$([a-zA-Z0-9_]+)\->[a-zA-Z0-9_]*$/', $input, $m)) {
    $var = $m[1];

    if (isset($GLOBALS[$var])) {
      $refl = new ReflectionClass($GLOBALS[$var]);

      $methods = $refl->getMethods(ReflectionMethod::IS_PUBLIC);
      foreach ($methods as $method) {
        $return[] = $method->name . '(';
      }

      $properties = $refl->getProperties(ReflectionProperty::IS_PUBLIC);
      foreach ($properties as $property) {
        $return[] = $property->name;
      }
    }
  }
  // Are we trying to auto complete a static class method, constant or property?
  else if (preg_match('/\$?([a-zA-Z0-9_]+)::(\$?)([a-zA-Z0-9_])*$/', $input, $m)) {
    $class = $m[1];
    $refl  = null;
    
    if (class_exists($class)) {
      $refl = new ReflectionClass($class);
    } else if (isset($GLOBALS[$class]) && is_object($GLOBALS[$class])) {
      $refl = new ReflectionClass($GLOBALS[$class]);
    }

    if (!is_null($refl)) {
      if (empty($m[2])) {
        $methods = $refl->getMethods(ReflectionMethod::IS_STATIC);
        foreach ($methods as $method) {
          if ($method->isPublic()) {
            $return[] = $class . '::' . $method->name . '(';
          }
        }

        $constants = $refl->getConstants();
        foreach ($constants as $constant => $value) {
          $return[] = $class . '::' . $constant;
        }
      }

      if (!empty($m[2]) || empty($m[3])) {
        $properties = $refl->getProperties(ReflectionProperty::IS_STATIC);
        foreach ($properties as $property) {
          if ($property->isPublic()) {
            $return[] = $class . '::$' . $property->name;
          }
        }
      }
    }
  } else if (preg_match('/\'[^\']*$/', $input) || preg_match('/"[^"]*$/', $input)) {
    return false; // This makes readline auto-complete files
  } else if (preg_match('/\$[a-zA-Z0-9_]*$/', $input)) {
    $return = array_keys($GLOBALS);
  } else {
    $functions = get_defined_functions();

    $classes = get_declared_classes();

    $functions['internal'] = array_map(function($v) {
      return $v . '(';
    }, $functions['internal']);
    
    $functions['user'] = array_map(function($v) {
      return $v . '(';
    }, $functions['user']);

    $return = array_merge($return, $classes, $functions['user'], $functions['internal'], array('require ', 'echo '));
  }

  if (empty($return)) {
    return array('');
  }

  return $return;
});


// Read the history or try to create the history file
//$___histfile = posix_getpwuid(posix_getuid());
//$___histfile = $___histfile['dir'] . '/.repl-history';
$___histfile = './.repl-history';
$___histsize = getenv('HISTSIZE') ?: 1000;


if (file_exists($___histfile)) {
  if (!readline_read_history($___histfile)) {
    $___histfile = false;
  }
} else if (!touch($___histfile)) {
  $___histfile = false;
}


for (;;) {
  $___s = readline('> ');

  if ($___s === false) {
    break;
  }

  readline_add_history($___s);

  if ($___histfile !== false) {
    readline_write_history($___histfile);


    // the PHP readline extension doesn't allow you to set the history size.
    // So we truncate the file to HISTSIZE ourselves.
    $fp    = fopen($___histfile, 'c+');
    $head  = fgets($fp);
    $lines = array();
    while (($s = fgets($fp)) !== false) {
      $lines[] = $s;
    }

    $lines = array_slice($lines, -$___histsize);

    ftruncate($fp, 0);
    rewind($fp);

    fwrite($fp, $head);
    fwrite($fp, implode('', $lines));

    fclose($fp);
  }

  $___s = make_command_executable($___s);

  try {
    $___r = eval($___s);
  } catch (Exception $___ex) {
    echo '! ' . $___ex . "\n";

    continue;
  }

  if (is_object($___r)) {
    var_dump_object($___r);
  } else {
    var_dump($___r);
  }
}

