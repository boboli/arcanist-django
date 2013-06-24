<?php


const COVERAGE_TEMP_DIR = "_coverage_temp";
const DLINEBREAK =
"======================================================================";
const LINEBREAK =
"----------------------------------------------------------------------";


final class DjangoUnitTestEngine extends ArcanistBaseUnitTestEngine {
    public function getAppNames() {
        $working_copy = $this->getWorkingCopy();
        return $working_copy->getConfig("unit.engine.django.test_apps",
                                        "");
    }

    public function run() {
        $appNames = $this->getAppNames();

        $resultsArray = array();
        // find all manage.py files
        $managepyDirs = array();

        // look at all paths, and recursively look for a manage.py, only going
        // up in directories
        foreach ($this->getPaths() as $path) {
            $rootPath = $path;

            do {
                if(file_exists($rootPath."/manage.py") &&
                        !in_array($rootPath, $managepyDirs)) {
                    array_push($managepyDirs, $rootPath);
                }

                $last = strrchr($rootPath, "/");
                $rootPath = str_replace($last, "", $rootPath);
            } while ($last);
        }

        if(file_exists("./manage.py") && !in_array(".", $managepyDirs)) {
            array_push($managepyDirs, ".");
        }

        if(count($managepyDirs) == 0) {
            throw new ArcanistNoEffectException(
                "Could not find a manage.py. No tests to run.");
        }

        // each manage.py found is a django project to test
        foreach ($managepyDirs as $managepyDir) {
            $managepyPath = $managepyDir."/manage.py";

            // store the ArcanistUnitTestResults for this project
            $results = array();

            // cleans coverage results from any previous runs
            exec("coverage erase");
            // runs tests with code coverage for specified app names,
            // only giving results on files in pwd (to ignore 3rd party
            // code), verbosity 2 for individual test results, pipe stderr to
            // stdout as the test results are on stderr
            exec("coverage run --source='.' $managepyPath test -v2 $appNames 2>&1",
                 $testLines, $testExitCode);

            // buffer to help with regex finds, as we use multiline patterns
            $strbuf = "";
            foreach ($testLines as $testLine) {
                $strbuf .= $testLine."\n";

                // pattern for a test run:
                // test_blah blah (some.package.SimpleTest) blahblah ... ok
                while(preg_match("/(test_.*? \(.*?\)).*? \.\.\. (.*)\n/s",
                                 $strbuf,
                                 $testStatusMatches,
                                 PREG_OFFSET_CAPTURE)) {
                    $result = new ArcanistUnitTestResult();

                    // name of the test
                    $testName = $testStatusMatches[1][0];
                    // result (e.g. "ok", "FAIL")
                    $testResult = $testStatusMatches[2][0];
                    $result->setName($testName);
                    // set to default empty, this is the details  displayed
                    // when there are errors
                    $result->setUserData("");

                    if($testResult =="ok") {
                        $result->setResult(
                            ArcanistUnitTestResult::RESULT_PASS);
                    } else if($testResult == "FAIL") {
                        $result->setResult(
                            ArcanistUnitTestResult::RESULT_FAIL);
                    } else if($testResult == "ERROR") {
                        $result->setResult(
                            ArcanistUnitTestResult::RESULT_FAIL);
                    } else if(strpos($testResult, "skipped") == 0) {
                        $result->setResult(
                            ArcanistUnitTestResult::RESULT_SKIP);
                        // sets the skip reason as the UserData (displayed on
                        // arc unit test results)
                        $result->setUserData(substr($testResult, 8));
                    } else {
                        // if we don't recognize the test result, default to
                        // RESULT_UNSOUND
                        $result->setResult(
                            ArcanistUnitTestResult::RESULT_UNSOUND);
                    }

                    // add to dict of UnitTestResults, keyed on name
                    $results[$testName] = $result;

                    // flush strbuf up to the end of the regex match
                    $end = $testStatusMatches[0][1] +
                           strlen($testStatusMatches[0][0]);
                    $strbuf = substr($strbuf, $end);
                }

                // pattern for the error/traceback of a failed test:
                // ===...
                // FAIL: test_blah blah
                // ---...
                // Traceback lines
                // more tracebacklines
                // (empty line, so "\n\n")
                while(preg_match(
                        "/".DLINEBREAK."\n(FAIL|ERROR): (.*)\n".LINEBREAK."\n(.*?)\n\n/s",
                        $strbuf,
                        $failMatches,
                        PREG_OFFSET_CAPTURE)) {
                    // name of the test
                    $testName = $failMatches[2][0];
                    // error/traceback string
                    $errorStr = $failMatches[3][0];

                    // only set UserData on the ArcanistUnitTestResult if it
                    // exists
                    if(array_key_exists($testName, $results)) {
                        $results[$testName]->setUserData($errorStr);
                    }

                    // flush strbuf up to the end of the regex match
                    $end = $failMatches[0][1] +
                           strlen($failMatches[0][0]);
                    $strbuf = substr($strbuf, $end);
                }
            }

            // if we have not found any tests in the output, but the exit code
            // wasn't 0, the entire test suite has failed to run, since it ran
            // no tests
            if(count($results) == 0 && $testExitCode != 0) {
                // name the test "Failed to run tests: " followed by the path
                // of the manage.py file
                $failTestName = "Failed to run: ".$managepyPath;
                $result = new ArcanistUnitTestResult();
                $result->setName($failTestName);
                $result->setResult(ArcanistUnitTestResult::RESULT_FAIL);
                // set the UserData to the raw output of the failed test run
                $result->setUserData(join("\n", $testLines));

                // add to final results array
                $resultsArray[$failTestName] = $result;
                // skip coverage as there is none
                continue;
            }

            $coverageTempDir = $managepyDir."/".COVERAGE_TEMP_DIR;

            // generate annotated source files to find out which lines have
            // coverage
            exec("coverage annotate -d ".$coverageTempDir);

            // store all the coverage results for this project
            $coverageArray = array();

            // dig through annotated coverage directory for coverage.py-
            // generated ",cover" files
            foreach(glob($coverageTempDir."/*,cover") as $coverFilePath) {
                if(preg_match("/__init\.py/", $coverFilePath)) {
                    continue;
                }
                // name of files are "path_to_file.py,cover", so match the
                /// "path_to_file.py" part
                if(preg_match(":^$coverageTempDir/(.*),cover:",
                                  $coverFilePath, $matches)) {
                    $srcFilePath = $matches[1];

                    // replace "_" with "/" to get real path
                    $srcFilePath = str_replace("_", "/", $srcFilePath);
                    $coverageStr = "";

                    foreach(file($coverFilePath) as $coverLine) {
                        switch($coverLine[0]) {
                            case '>':
                                $coverageStr .= 'C';
                                break;
                            case '!':
                                $coverageStr .= 'U';
                                break;
                            case ' ':
                                $coverageStr .= 'N';
                                break;
                            case '-':
                                $coverageStr .= 'X';
                                break;
                            default:
                                break;
                        }
                    }

                    // only add to coverage report if the path was originally
                    // specified by arc
                    if(in_array($srcFilePath, $this->getPaths())) {
                        $coverageArray[$srcFilePath] = $coverageStr;
                    }
                }
            }

            // have all ArcanistUnitTestResults for this project have coverage
            // data for the whole project
            foreach($results as $path => $result) {
                $result->setCoverage($coverageArray);
            }

            $resultsArray = array_merge($resultsArray, $results);
        }

        return $resultsArray;
    }
}
