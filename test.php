<?php
//http://localhost/devcore/rcredits/test?module=rcredits/rsms&menu=1
use rCredits as r; // only for saving current user
use rCredits\Util as u;
global $okAll, $noAll; $okAll = $noAll = 0; // overall results counters
global $the_menu, $the_feature, $the_div, $the_scene, $the_variant; // allows for arbitrarily selective testing
global $programPath; $programPath = $_SERVER['REDIRECT_URL'];
define('TESTING', 1); // use this to activate extra debugging statements (if (t\EST()))
define('MAX_DIVLESS', 7); // maximum number of features before module gets subdivided
define('DIV_SIZE', 6);
ini_set('max_execution_time', 0); // don't ever timeout when testing

$cmdline = $_SERVER['QUERY_STRING'];

$args = array();
if (!@$q) $q = $cmdline; // get query string from cache if possible
if ($q) {
  foreach (explode('&', $q) as $one) { // gotta do it the long way, because Drupal suppresses $_POST
    list ($key, $value) = explode('=', $one);
    $args[$key] = $value;
  }
}
extract(u\just('menu module feature div scene variant', $args), EXTR_PREFIX_ALL, 'the'); 
global $testModule; $testModule = !@$the_feature; // testing whole module? (suppress some test output)
//if (@$the_scene) $the_variant = 0;
$modules = @$the_module ? array($the_module) : array('rcredits/rsms', 'rcredits/rsmart', 'rcredits/rweb',  'rcredits/rcron'); // and admin
//u\deb("top of test.php: okAll=$okAll modules=" . print_r($modules, 1));

foreach($modules as $module) doModule($module);
if (!@$the_menu) {
  if (count($modules) > 1) report('OVERALL', $okAll, $noAll);
  insertMessage('<a href="http://localhost/devcore/rcredits/test?menu=1">Test Menu</a>');
}

// END OF PROGRAM

// SMS: OpenAnAccountForTheCaller AbbreviationsWork ExchangeForCash GetHelp GetInformation Transact Undo OfferToExchangeUSDollarsForRCredits
// Smart: Startup IdentifyQR Transact UndoCompleted UndoPending UndoAttack Insufficient Change
// Web: Signup

//  $features = array('UndoPending'); // uncomment to run just one feature (test set)
//  $the_scene = 'testTheCallerAsksToPayAMemberId'; // uncomment to run just one test scenario
//  $the_variant = 0; // uncomment to focus on a single variant (usually 0)

/**
 * Run tests for one module
 */
function doModule($module) {
  global $ok, $no, $fails, $okAll, $noAll, $the_feature, $the_div, $the_menu, $programPath;
  global $base_url, $overallResults;
  $fails = $ok = $no = 0; // results counters

  $moduleName = strtoupper(basename($module));
  $path = __DIR__ . "/../$module"; // relative path from test program to module directory
  $compilerPath = "$base_url/sites/all/modules/gherkin/compile.php?module=$module";
  $compilation = file_get_contents($compilerPath); // recompile tests first
  if (strpos($compilation, 'ERROR:') !== FALSE) {
    report($moduleName, 0, "<a href='$compilerPath'>compile error</a>", $module, $the_div);
    return;
  }
  $features = str_replace("$path/features/", '', str_replace('.feature', '', findFiles("$path/features", '/.*\.feature$/')));
  $featureCount = count($features);
  if (@$the_feature) {
    $features = array($the_feature);
  } elseif (@$the_div and $featureCount > MAX_DIVLESS) {
    $features = array_slice($features, ($the_div - 1) * DIV_SIZE, DIV_SIZE);
  }
  $link = testLink('ALL', $module);
  if (@$the_menu) { // just show the choices
    $menu = array();
    $f = 1;
    $div = 0;
    foreach ($features as $feature) {
//      if ($featureCount > MAX_DIVLESS and $f == 1) {
      if ($f == 1) {
        $div++;
        $divLink = testLink("Div #$div", $module, $div);
        $menu[] = "<br><b style='font-size:120%; margin:0 0 0 0;'>$divLink: </b>";
      }
      $menu[] = testLink($feature, $module, '', $feature) . ' , ';
      $f = $f < DIV_SIZE ? $f + 1 : 1;
    }

    insertMessage("<h1 style='margin:18px 0 -18px 0;'>$moduleName: $link</h1>" . join('', $menu));
  } else {
    $overallResults = array();
    foreach (array('error', 'warning', 'status') as $type) $overallResults[$type] = array();
    foreach ($features as $feature) doTest($module, $feature);
    foreach ($overallResults as $type => $one) foreach($one as $msg) {
      if ($type == 'error') $msg = color('ERRS: ' . print_r($msg, 1), 'salmon');
      \drupal_set_message($msg);
    }
    
    $featureLink = @$the_feature ? ' (' . testLink($feature, $module, '', $feature) . ')' : '';
    report($moduleName . $featureLink, $ok, $no, $module, $the_div);
  }
}  

function doTest($module, $feature) {
///  debug(compact('module','feature'));
  global $results, $user, $the_menu, $the_module, $the_feature, $the_scene, $the_variant, $resumeAt, $skipToStep;
  global $okAll, $noAll, $overallResults;
  
/*  if (@$resumeAt and strpos($resumeAt, "$module:$feature:") === FALSE) {
  u\deb("skipping $module:$feature: resumeAt=$resumeAt");
    return; // not to the right feature yet
  }
  */
  include ($feature_filename = __DIR__ . "/../$module/tests/$feature.test");

  $featureLink = testLink($feature, $module, '', $feature);
  $classname = basename($module . $feature);
  $t = new $classname();
  $s = file_get_contents($feature_filename);
  preg_match_all('/function (test.*?)\(/sm', $s, $matches);

  foreach ($matches[1] as $one) {
    list ($scene, $variant) = explode('_', $one);
    if (@$the_scene) if ($scene != $the_scene) continue;
    if (@$the_variant !== '') if ($variant != $the_variant) continue;

///    debug("DOING $module:$feature:$one");
    u\deb("DOING $module:$feature:$one");
    $saveSESSION = $_SESSION; $_SESSION = array(); // start each test with a clean slate
    $results = array('PASS!');
    
    $mya = r\acct(); // save true account (that is running the tests), so we can restore it
    $t->$one(); // run one test
    r\acct::setDefault($mya); // restore tester's account

    u\deb('after test');
    // Display results are intermixed w debugging output, if any (so don't collect results before displaying)
    $link = testLink(substr($one, 4), $module, '', $feature, $scene, $variant); // drop "test" from description
    $results[0] .= " [$featureLink] $link";
    $results[0] = color($results[0], 'darkkhaki');
    drupal_set_message(join(PHP_EOL, $results));

    $msgs = @$_SESSION['messages'] ?: array();
    foreach (array('error', 'warning', 'status') as $one) {
      foreach ((@$msgs[$one] ?: array()) as $msg) $overallResults[$one][] = $msg;
    }
    
u\deb('before restore count overallResults:' . count($overallResults));
    $_SESSION = $saveSESSION;
    u\deb("done with $module:$feature:$one resumeAt=$resumeAt skipToStep=$skipToStep");
  }
  u\deb("done with $module:$feature resumeAt=$resumeAt skipToStep=$skipToStep");
}

class DrupalWebTestCase {
  function setUp() {}
  function assertTrue($bool, $step, $sceneName) {
    global $results, $skipToStep, $resumeAt;
    global $ok, $no, $fails, $okAll, $noAll;
    
    if (r\usd::inAtom()) {
      t\output("in atom after $step: $sceneName!");
      while (r\usd::inAtom()) r\usd::rollback();
      $bool = FALSE;
    }

    $step = str_replace('"\\', '', $step); // for example "\user_login"
    $step = str_replace('\\', "\n     ", $step); // end of data lines
    $step = str_replace("''", '"', $step); // convention in .feature files

    if (@$skipToStep) {
        u\deb("SKIPPING scene=$sceneName step=$step resumeAt=$resumeAt skipToStep=$skipToStep");
      return;
    }

//    u\deb("NOT SKIPPING step=$step resumeAt=$resumeAt skipToStep=$skipToStep");
    $where = $sceneName == 'Setup' ? "[$sceneName] " : '';
    list ($result, $color) = $bool ? array('OK', 'lightgreen') : array('NO', 'yellow');
    $results[] = $result = color("$result: $where$step", $color);
    if ($bool) {
      $ok++; $okAll++;
    } else {
      $no++; $noAll++;
      if (!strpos($results[0], 'FAIL')) {
        $fails++;
        $results[0] = gotoError('FAIL', $fails);
      }
    }
  }
}

function color($msg, $color) {
  return "<pre style='background-color:$color;'>$msg</pre>";
}

function insertMessage($s, $type = 'status') {
  if (!is_string($s)) $s = print_r($s, 1);
  if (!@$_SESSION['messages'][$type]) $_SESSION['messages'][$type] = array();
  array_unshift($_SESSION['messages'][$type], $s);
}

function report($moduleName, $ok, $no, $module = '', $div = '') {
  $moduleName = testLink($moduleName, $module);
  if ($div) $moduleName .= ' ' . testLink("Div #$div", $module, $div);
  if ($no) {
    if (!strpos($no, 'a href')) $no = gotoError($no); // add link unless it's already there
  } else $no = '_';
  
  $msg = <<<EOF
  <h1>
  $moduleName - 
  ok: <span style='color:lightgreen; font-size:300%;'>$ok</span> 
  no: <span style='color:red; font-size:300%;'>$no</span>
  </h1>
EOF;
  insertMessage($msg);
}

/**
 * Return a link to the given module, div, feature, scene, or variant.
 */
function testLink($description, $module, $div = '', $feature = '', $scene = '', $variant = '') {
  global $programPath, $the_menu;
  $description = str_replace('_0', '', $description); // omit first variant from description
  //$style = $the_menu ? "style='margin-left:50px;'" : '';
  return "<a href='$programPath?module=$module&div=$div&feature=$feature&scene=$scene&variant=$variant&restart=1'>$description</a>";
}

function gotoError($title, $errorNum = 0) {
  $next = $errorNum + 1;
  $link = "javascript:document.getElementById('testError$next').scrollIntoView(true); window.scrollBy(0, -50);";
  return "<a id='testError$errorNum' href=\"$link\">$title</a>";
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
  if (!is_dir($path)) die("No features folder found for that module (path $path).");
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
