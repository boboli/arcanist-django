<?php

final class DjangoLintEngine extends ArcanistLintEngine {
    public function buildLinters() {
        $paths = $this->getPaths();

        foreach ($paths as $key => $path) {
            // ignore all removed files
            if (!$this->pathExists($path)) {
                unset($paths[$key]);
            }
        }

        $py_paths = preg_grep("/\.py$/", $paths);
        $linters[] = id(new ArcanistGeneratedLinter())->setPaths($py_paths);
        $linters[] = id(new ArcanistNoLintLinter())->setPaths($py_paths);
        $linters[] = id(new ArcanistTextLinter())->setPaths($py_paths);
        $linters[] = id(new ArcanistSpellingLinter())->setPaths($py_paths);
        $linters[] = id(new ArcanistFlake8Linter())->setPaths($py_paths);

        return $linters;
    }
}
