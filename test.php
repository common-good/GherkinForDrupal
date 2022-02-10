<?php
use CG\DB as db;

require_once R_ROOT . '/cg-db.inc';

/**
 * @file
 * Common Good Acceptance Test Program
 * Call as include file (parameters set before include)
 * Expects array $modules: list of module paths to display features for (relative to this file's parent)
 *
 * query parameters in URL:
 * @param string $module: path of test module to run (relative to this file's parent)
 * @param string $feature: which specific feature within that module (defaults to all)
 * @param string $scene: which specific scenario within that feature (defaults to all)
 * @param int $variant: which specific variant within that scene (defaults to all)
 * @param bool $compile: <recompile the tests before running them>
 */

define('TESTING', 1); // use this to activate extra debugging statements (if (u\test()))
define('MAX_DIVLESS', 7); // maximum number of features before module gets subdivided
define('DIV_SIZE', 10);
ini_set('max_execution_time', 0); // don't ever timeout when testing

global $T; $T = new stdClass();

$T = (object) just('module feature div scene variant compile', $args = $_SERVER['QUERY_STRING'], NULL);
$T->okAll = $T->noAll = 0; // overall results counters
$T->programPath = BASE_URL . '/' . strstr($_SERVER['REQUEST_URI'] . '?', '?', TRUE);
$T->wholeModule = !nn($T->feature); // testing whole module? (suppress some test output)
//if (@$T->scene) $T->variant = 0;

if ($T->module) $modules = explode(',', $T->module); // one or more
foreach($modules as $module) doModule($module, $menu = empty($args));

if ($menu) {
  insertMessage('<div class="compile"><input type="checkbox" name="compile" checked="checked" /> <label for="compile">Compile</label></div>');
} elseif (count($modules) > 1) report('OVERALL', $T->okAll, $T->noAll);

// END OF PROGRAM

/**
 * Run tests for one module
 * @param string $module: relative path of module to run
 * @param bool $menu: show just the menu
 */
function doModule($module, $menu) {
  global $T, $tTime; $tTime = time();
  $T->fails = $T->ok = $T->no = 0; // results counters
  $timezone = date_default_timezone_get();

  $moduleName = strtoupper(basename($module));
  $path = DRUPAL_ROOT . "/$module"; // path to module directory
  $compilerPath = preg_replace('~:[0-9]*/~', ':/', LOCAL_URL . "/vendor/gherkin/compile.php?lang=PHP&path=$path&timezone=$timezone&time=$tTime"); 

  if ($T->compile and !$menu) {
    if (isDEV) {
      $contextOptions = ['ssl' => ray('verify_peer verify_peer_name allow_self_signed SNI_enabled', FALSE, FALSE, TRUE, TRUE)];
      $compilation = file_get_contents($compilerPath, FALSE, stream_context_create($contextOptions)); // recompile tests first
    } else {
      $compilation = file_get_contents($compilerPath); // recompile tests first
    }
    if (strhas($compilation, 'ERROR ') or strhas($compilation, 'Fatal error') or strhas($compilation, 'Parse error') or !strhas($compilation, 'SUCCESS!')) {
/**/  die("<b class=\"err\">Gherkin Compiler error</b> compiling module $module (fix, go back, retry):<br>$compilation");
      return report($moduleName, 0, "<a href=\"$compilerPath\">compile error</a>", $module, $T->div);
    }
  }
  $features = str_replace("$path/features/", '', str_replace('.feature', '', findFiles("$path/features", '/.*\.feature$/')));
  // foreach ($features = findFiles("$path", '/.*\.feature$/') as $i => $flnm) $features[$i] =  str_replace('.feature', '', basename($flnm));
  $featureCount = count($features);
  $Tf = nn($T->feature); // remember the requested feature, if any
  if ($Tf) {
    $features = array($Tf);
  } elseif (nn($T->div) and $featureCount > MAX_DIVLESS) {
    $features = array_slice($features, ($T->div - 1) * DIV_SIZE, DIV_SIZE);
  }
  $link = testLink('ALL', $module);
  if (nn($menu)) { // just show the choices
    $menu = array();
    $f = 1;
    $div = 0;
    foreach ($features as $feature) {
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
    foreach ($features as $T->feature) doTest($module, $T->feature);
    
    $lastNextLink = gotoError('', $T->fails);
    $fix = str_replace('NEXT</a>', '</a><big><b>LAST</b></big>', $lastNextLink);
    $types = ray('status error warning');
    $q = db\q('select * FROM test WHERE type IN (:types)', compact('types'));
    while ($row = $q->fetchAssoc()) {
      extract($row);
      if ($type == 'status' and strpos($value, 'NEXT')) $value = str_replace($lastNextLink, $fix, $value);
      if ($type == 'error') $value = color('ERRS: ' . pr($value), 'test-error');
      db\update('test', compact(ray('id type value')), 'id');
    }
    
    $featureLink = $Tf ? ' (' . testLink($Tf, $module, '', $Tf) . ')' : '';
    report($moduleName . $featureLink, $T->ok, $T->no, $module, $T->div);
  }
}  

function doTest($module, $feature) {
  global $T;
  
  include ($featureFilename = DRUPAL_ROOT . "/$module/test/$feature.test");

  $featureLink = testLink($feature, $module, '', $feature);
  $classname = basename($module) . str_replace('-', '', $feature);
  $t = new $classname();
  $s = file_get_contents($featureFilename);
  preg_match_all('/function (test.*?)\(/sm', $s, $matches);

  foreach ($matches[1] as $one) {
    list ($scene, $variant) = explode('_', $one);
    if (nn($T->scene)) if ($scene != $T->scene) continue;
    if (nn($T->variant) !== '') if ($variant != $T->variant) continue;

    $T->results = array('PASS!');

    // Display results are intermixed w debugging output, if any (so don't collect results before displaying)

    $xfails = nn($T->fails);
    $t->$one(); // run one test
    $link = testLink($testName = substr($scene, 4), $module, '', $feature, $scene, $variant); // drop "test" from description
    $retryLink = ' ' . str_replace("$testName<", 'Retry1<', $link) . ' ';
    if (!nn($T->firstTestLink)) $T->firstTestLink = $retryLink;
    if ($T->fails != $xfails and !nn($T->firstFailLink)) $T->firstFailLink = $retryLink;

    $T->results[0] .= ".......... [$featureLink] $link";
    $T->results[0] = color($T->results[0], 'pass');
    insertMessage(join(PHP_EOL, $T->results), 'bottom');
  }
}

function expect($bool) {
  global $T;
  global $sceneTest;

  $step = htmlspecialchars($sceneTest->step); // make sure it displays properly

  $where = $sceneTest->name == 'Setup' ? "[Setup] " : '';
  list ($result, $color) = $bool ? array('OK', 'success') : array('NO', 'fail');
  $T->results[] = $result = color("$result: $where$step", $color);
  if ($bool) {
    $T->ok++; $T->okAll++;
  } else {
    $T->no++; $T->noAll++;
    if (!strpos($T->results[0], 'FAIL')) {
      $T->fails++;
      $T->results[0] = gotoError('<br>FAIL', $T->fails);
    }
  }
}

function color($msg, $color) {return "<pre class=\"test-$color\">$msg</pre>";}

/**
 * Insert a message at the top or bottom of the message list.
 */
function insertMessage($value, $where = 'top') {
  $type = 'status';
  if (!is_string($value)) $value = pr($value);
  $info = compact(ray('type value'));
  if ($where == 'top') $info['id'] = min(0, db\min('id', 'test')) - 1;
/**/  if (!db\insert('test', $info, 'id')) die('failed to insert into test table');
}

function report($moduleName, $ok, $no, $module = '', $div = '') {
  global $T;
  
  $retryLink = nn($T->firstFailLink) ?: nn($T->firstTestLink);
  
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
  global $T;
//  $description = str_replace('_0', '', $description); // omit first variant from description
  return "<a href=\"$T->programPath?module=$module&div=$div&feature=$feature&scene=$scene&variant=$variant&restart=1&compile=1\">$description</a>";
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
