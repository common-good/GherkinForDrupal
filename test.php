<?php
/**
 * @file
 * Drupal Acceptance Test Program
 * Call as include file or stand-alone:
 *
 * INCLUDE FILE (parameters set before include)
 * @param array $modules: list of module paths to display features for (relative to this file's parent)
 *
 * STAND-ALONE (query parameters in URL)
 * @param string $module: path of test module to run (relative to this file's parent)
 * @param string $feature: which specific feature within that module (defaults to all)
 * @param string $scene: which specific scenario within that feature (defaults to all)
 * @param int $variant: which specific variant within that scene (defaults to all)
 */
global $okAll, $noAll; $okAll = $noAll = 0; // overall results counters
global $the_feature, $the_div, $the_scene, $the_variant; // allows for arbitrarily selective testing
global $programPath; $programPath = $_SERVER['REDIRECT_URL'];
define('TESTING', 1); // use this to activate extra debugging statements (if (u\test()))
define('MAX_DIVLESS', 7); // maximum number of features before module gets subdivided
define('DIV_SIZE', 10);
ini_set('max_execution_time', 0); // don't ever timeout when testing

parse_str($_SERVER['QUERY_STRING'], $args);
extract($args, EXTR_PREFIX_ALL, 'the'); 
global $wholeModule; $wholeModule = !@$the_feature; // testing whole module? (suppress some test output)
//if (@$the_scene) $the_variant = 0;
if (@$the_module) $modules = explode(',', $the_module);

foreach($modules as $module) doModule($module, $menu = !@$args);
if (!$menu) {
  if (count($modules) > 1) report('OVERALL', $okAll, $noAll);
  insertMessage("<a href=\"sadmin/tests\">Test Menu</a>");
}

// END OF PROGRAM

/**
 * Run tests for one module
 * @param string $module: relative path of module to run
 * @param bool $menu: show just the menu
 */
function doModule($module, $menu) {
  global $ok, $no, $fails, $okAll, $noAll, $the_feature, $the_div, $programPath;
  global $base_url, $overallResults;
  $fails = $ok = $no = 0; // results counters

  $moduleName = strtoupper(basename($module));
  $path = DRUPAL_ROOT . "/$module"; // path to module directory
  $compilerPath = preg_replace('~:[0-9]*/~', ':/', LOCAL_URL . "/vendor/gherkin/compile.php?lang=PHP&path=$path"); 

  if (!$menu) {
    if (isDEV) {
      $arrContextOptions = [ "ssl" => [ 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'SNI_enabled' => true ] ];
      $compilation = file_get_contents($compilerPath, false, stream_context_create($arrContextOptions)); // recompile tests first
    } else {
      $compilation = file_get_contents($compilerPath); // recompile tests first
    }
    if (strpos($compilation, 'ERROR ') !== FALSE or strpos($compilation, 'Fatal error') or strpos($compilation, 'Parse error') ) {
/**/  die("<b class=\"err\">Gherkin Compiler error</b> compiling module $module (fix, go back, retry):<br>$compilation");
      return report($moduleName, 0, "<a href=\"$compilerPath\">compile error</a>", $module, $the_div);
    }
  }
  $features = str_replace("$path/features/", '', str_replace('.feature', '', findFiles("$path/features", '/.*\.feature$/')));
  // foreach ($features = findFiles("$path", '/.*\.feature$/') as $i => $flnm) $features[$i] =  str_replace('.feature', '', basename($flnm));
  $featureCount = count($features);
  if (@$the_feature) {
    $features = array($the_feature);
  } elseif (@$the_div and $featureCount > MAX_DIVLESS) {
    $features = array_slice($features, ($the_div - 1) * DIV_SIZE, DIV_SIZE);
  }
  $link = testLink('ALL', $module);
  if (@$menu) { // just show the choices
    $menu = array();
    $f = 1;
    $div = 0;
    foreach ($features as $feature) {
//      if ($featureCount > MAX_DIVLESS and $f == 1) {
      if ($f == 1) {
        $div++;
        $divLink = testLink("Div #$div", $module, $div);
        $menu[] = "<br><b class=\"test-divlink\">$divLink: </b>";
      }
      $menu[] = testLink($feature, $module, '', $feature) . ' , ';
      $f = $f < DIV_SIZE ? $f + 1 : 1;
    }

    insertMessage("<h1 class=\"test-hdr\">$moduleName: $link</h1>" . join('', $menu));
  } else {
    $overallResults = array();
    foreach (array('error', 'warning', 'status') as $type) $overallResults[$type] = array();
    foreach ($features as $feature) doTest($module, $feature);
    foreach ($overallResults as $type => $one) foreach($one as $msg) {
/**/  if ($type == 'error') $msg = color('ERRS: ' . print_r($msg, 1), 'test-error');
      \drupal_set_message($msg);
    }
    
    $featureLink = @$the_feature ? ' (' . testLink($feature, $module, '', $feature) . ')' : '';
    report($moduleName . $featureLink, $ok, $no, $module, $the_div);
  }
}  

function doTest($module, $feature) {
///  debug(compact('module','feature'));
  global $results, $user, $the_feature, $the_scene, $the_variant, $resumeAt, $skipToStep;
  global $overallResults, $fails, $firstFailLink, $firstTestLink;
  
/*  if (@$resumeAt and strpos($resumeAt, "$module:$feature:") === FALSE) {
  u\deb("skipping $module:$feature: resumeAt=$resumeAt");
    return; // not to the right feature yet
  }
  */

  include ($featureFilename = DRUPAL_ROOT . "/$module/test/$feature.test");

  $featureLink = testLink($feature, $module, '', $feature);
  $classname = basename($module) . str_replace('-', '', $feature);
///  print_r(compact('module','feature','classname')); die('in test');
  $t = new $classname();
  $s = file_get_contents($featureFilename);
  preg_match_all('/function (test.*?)\(/sm', $s, $matches);

  foreach ($matches[1] as $one) {
    list ($scene, $variant) = explode('_', $one);
    if (@$the_scene) if ($scene != $the_scene) continue;
    if (@$the_variant !== '') if ($variant != $the_variant) continue;

///    debug("DOING $module:$feature:$one");
    //u\deb("DOING $module:$feature:$one");
    $saveSESSION = $_SESSION; $_SESSION = array(); // start each test with a clean slate
    $results = array('PASS!');
/*    
    $mya = r\acct(); // save true account (that is running the tests), so we can restore it
    $t->$one(); // run one test
    r\acct::setDefault($mya); // restore tester's account
*/
    // Display results are intermixed w debugging output, if any (so don't collect results before displaying)

    $xfails = @$fails;
    $t->$one(); // run one test
    $link = testLink($testName = substr($scene, 4), $module, '', $feature, $scene, $variant); // drop "test" from description
    $retryLink = ' ' . str_replace("$testName<", 'Retry1<', $link) . ' ';
    if (!@$firstTestLink) $firstTestLink = $retryLink;
    if ($fails != $xfails and !@$firstFailLink) $firstFailLink = $retryLink;

    $results[0] .= ".......... [$featureLink] $link";
    $results[0] = color($results[0], 'pass');
    drupal_set_message(join(PHP_EOL, $results));

    $msgs = @$_SESSION['messages'] ?: array();
    foreach (array('error', 'warning', 'status') as $one) {
      foreach ((@$msgs[$one] ?: array()) as $msg) $overallResults[$one][] = $msg;
    }
    
//u\deb('before restore count overallResults:' . count($overallResults));
    $_SESSION = $saveSESSION;
    //u\deb("done with $module:$feature:$one resumeAt=$resumeAt skipToStep=$skipToStep");
  }
  //u\deb("done with $module:$feature resumeAt=$resumeAt skipToStep=$skipToStep");
}

function expect($bool) {
  global $results;
  global $ok, $no, $fails, $okAll, $noAll;
  global $sceneTest, $sceneName;

  $step = htmlspecialchars($sceneTest->step); // make sure it displays properly

  $where = $sceneName == 'Setup' ? "[Setup] " : '';
  list ($result, $color) = $bool ? array('OK', 'success') : array('NO', 'fail');
  $results[] = $result = color("$result: $where$step", $color);
  if ($bool) {
    $ok++; $okAll++;
  } else {
    $no++; $noAll++;
    if (!strpos($results[0], 'FAIL')) {
      $fails++;
      $results[0] = gotoError('<br>FAIL', $fails);
    }
  }
}

function color($msg, $color) {
  return "<pre class=\"test-$color\">$msg</pre>";
}

function insertMessage($s, $type = 'status') {
/**/  if (!is_string($s)) $s = print_r($s, 1);
  if (!@$_SESSION['messages'][$type]) $_SESSION['messages'][$type] = array();
  array_unshift($_SESSION['messages'][$type], $s);
}

function report($moduleName, $ok, $no, $module = '', $div = '') {
  global $firstFailLink, $firstTestLink;
  
  $retryLink = @$firstFailLink ?: $firstTestLink;
  
  $moduleName = testLink($moduleName, $module);
  if ($div) $moduleName .= ' ' . testLink("Div #$div", $module, $div);
  if ($no) {
    if (!strpos($no, 'a href')) $no = gotoError($no); // add link unless it's already there
  } else $no = '_';
  
  $msg = <<<EOF
  <h1>
  $moduleName - 
  ok: <span class="test-report-ok">$ok</span> 
  no: <span class="test-report-no">$no</span>
  $retryLink
  </h1>
EOF;
  insertMessage($msg);
}

/**
 * Return a link to the given module, div, feature, scene, or variant.
 */
function testLink($description, $module, $div = '', $feature = '', $scene = '', $variant = '') {
  global $programPath;
//  $description = str_replace('_0', '', $description); // omit first variant from description
  return "<a href=\"$programPath?module=$module&div=$div&feature=$feature&scene=$scene&variant=$variant&restart=1\">$description</a>";
}

function gotoError($title, $errorNum = 0) {
  $next = $errorNum + 1;
  return "<a id='testError$errorNum' index=\"$next\" class=\"test-next\">NEXT</a> $title";
}

/**
 * Find Files (copied from gherkin)
 *
 * Return an array of files matching the given pattern.
 *
 * @param string $path (optional, defaults to current directory)
 *   the directory to search
 *
 * @param string $pattern (optional, defaults to all files)
 *   return filenames matching this pattern
 *
 * @param array $result (optional)
 *   partial results. if this array is supplied, then recurse subdirectories
 *
 * @return
 *   an array of filenames, qualified by path (including the initial directory $path)
 */
function findFiles($path = '.', $pattern = '/./', $result = '') {
  if (!$recurse = is_array($result)) $result = array();
/**/  if (!is_dir($path)) die("No features folder found for that module (path $path).");
  $dir = dir($path);
  
  while ($filename = $dir->read()) {
    if ($filename == '.' or $filename == '..') continue;
    $filename = "$path/$filename";
    if (is_dir($filename) and $recurse) $result = findFiles($filename, $pattern, $result);
    if (preg_match($pattern, $filename)){
      $result[] = $filename;
    }
  }
  return $result;
}
