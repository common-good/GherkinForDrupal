<?php
%FEATURE_HEADER
require_once __DIR__ . '/../%MODULE.steps';

class %MODULE%FEATURE_NAME {
  var $module;
  var $feature;
  var $name;
  var $step;

  public function setUp($sceneName, $variant = '') {
    global $sceneTest; $sceneTest = $this;
    global $testOnly;

    $this->module = '%MODULE';
    $this->feature = '%FEATURE_NAME';
    $this->name = $sceneName;
    if (function_exists('extraSetup')) extraSetup($this); // defined in %MODULE.steps

    $this->name = ' Setup';
    switch ($variant) {
      default: // fall through to case(0)
%SETUP_LINES
    }
    $this->name = $sceneName;
  }
%TESTS
}