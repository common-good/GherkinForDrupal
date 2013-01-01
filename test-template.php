<?php
%FEATURE_HEADER
require_once __DIR__ . '/%GHERKIN_PATH/test-defs.php';
require_once __DIR__ . '/../%MODULE.steps';

class %MODULE%FEATURE_NAME extends DrupalWebTestCase {
  var $subs; // percent parameters (to Given(), etc.) and their replacements (eg: %random1 becomes some random string)
  var $sceneName;
  const SHORT_NAME = '%FEATURE_NAME';
  const FEATURE_NAME = '%MODULE Test - %FEATURE_NAME';
  const DESCRIPTION = '%FEATURE_LONGNAME';
  const MODULE = '%MODULE';

  public function gherkin($statement, $type) {
    $this->assertTrue(gherkinGuts($statement, $type), $statement, $this->sceneName);
  }
  
  public static function getInfo() {
    return array(
      'short_name' => self::SHORT_NAME,
      'name' => self::FEATURE_NAME,
      'description' => self::DESCRIPTION,
      'group' => ucwords(self::MODULE)
    );
  }

  public function setUp() {} // must be compatible with DrupalWebTestCase::setUp()
  
  public function setUp2($sceneName, $variant = '') {
    global $sceneTest; $sceneTest = $this;
    parent::setUp(self::MODULE);

    $this->subs = usualSubs();
    $this->sceneName = __FUNCTION__;
    if (function_exists('extraSetup')) extraSetup($this); // defined in %MODULE.steps
    $this->sceneName = $sceneName;

    switch ($variant) {
    default: // fall through to case(0)
%SETUP_LINES
    }
  }
%TESTS
}