<?php

namespace Phulp\AngularFileSort;

use Phulp\DistFile;
use Phulp\PipeInterface;
use Phulp\Source;

class AngularFileSort implements PipeInterface
{
    protected $stack = [];
    protected $partialStack = [];
    protected $finalStack = [];
    protected $modulesName = [];
    protected $scriptsName = [];

    public function execute(Source $src)
    {
        foreach ($src->getDistFiles() as $key => $distFile) {
            $src->removeDistFile($key);
            if (! preg_match('/js$/', $distFile->getDistpathname())) {
                $this->finalStack[] = $distFile;
            } else {
                $this->prepare($distFile);
            }
        }

        $this->scriptsName = array_unique(array_filter($this->scriptsName));
        $this->modulesName = array_unique(array_filter($this->modulesName));

        foreach ($this->stack as $moduleName => & $module) {
            if ($moduleName == 'angular') {
                continue;
            }

            $matches = [];
            $content = $module['distFile']->getContent();
            $check = function ($v) use ($content) { return preg_match("/$v/", $content) ? $v : null; };
            $matches = array_unique(array_filter(array_map($check, $this->scriptsName)));

            $module['dependencies'] = array_merge($module['dependencies'], $matches);
            $module['dependencies'] = array_intersect($this->modulesName, $module['dependencies']);
            $module['dependencies'] = array_unique($module['dependencies']);
        }

        $self = $this;
        $order = function ($moduleName, $module) use (& $order, & $self) {
            if (isset($self->partialStack[$module['filename']])) {
                return;
            }

            foreach ($module['dependencies'] as $dependency) {
                if (
                    $dependency == $moduleName
                    || $module['filename'] == $self->stack[$dependency]['filename']
                ) {
                    continue;
                }
                if (! isset($self->partialStack[$self->stack[$dependency]['filename']])) {
                    $order($dependency, $self->stack[$dependency]);
                }
            }

            $self->partialStack[$module['filename']] = $module;
        };

        foreach ($this->stack as $moduleName => & $module) {
            $order($moduleName, $module);
        }

        $this->partialStack = array_reverse($this->partialStack);

        foreach ($this->partialStack as $module) {
            array_unshift($this->finalStack, $module['distFile']);
        }

        array_walk($this->finalStack, [$src, 'addDistFile']);
    }

    public function prepare(DistFile $distFile)
    {
        $matches = [];
        $regex = '/(angularModule[ \n]*\([ \n]*[\'"]{1}ng[\'"]{1}|angular[ \n]*\.[ \n]*module[ \n]*\([ \n]*[\'"]{1}[a-zA-Z0-9\.-\/]+[\'"]{1}[ \n]*,[ \n]*\[|(?:window|global|root)\.[a-zA-Z](?:\w|\-)+\s+\=)/';

        preg_match_all($regex, $distFile->getContent(), $matches);

        if (count($matches[0])) {
            foreach ($matches[0] as $stm) {
                if ($this->isNg($stm)) {
                    $moduleName = 'angular';
                    $this->stack['angular'] = [
                        'filename' => $distFile->getDistpathname(),
                        'distFile' => $distFile,
                        'dependencies' => [], // @todo
                    ];
                    $this->modulesName[] = $moduleName;
                } else if ($this->isModule($stm)) {
                    $moduleName = $this->getModuleName($stm);
                    $this->stack[$moduleName] = [
                        'filename' => $distFile->getDistpathname(),
                        'distFile' => $distFile,
                        'dependencies' => array_merge(
                            $this->getModuleDependencies($stm, $distFile->getContent()),
                            ['angular']
                        ),
                    ];
                    $this->modulesName[] = $moduleName;
                } elseif ($this->globalOrRoot($stm)) {
                    $scriptName = $this->getGlobalOrRootName($stm);
                    $this->stack[$scriptName] = [
                        'filename' => $distFile->getDistpathname(),
                        'distFile' => $distFile,
                        'dependencies' => [], // @todo
                    ];
                    $this->scriptsName[] = $scriptName;
                }
            }
        } else {
            $this->finalStack[] = $distFile;
        }
    }

    public function getModuleDependencies($stm, $content)
    {
        $dependencies = preg_replace(
            '/.*' . preg_quote($stm) . '([a-zA-Z0-9\.\-\'\"\/,\s]*)\].*/s',
            '$1',
            $content
        );

        $dependencies = preg_replace('/(\s|\'|")/', null, $dependencies);

        return array_filter(explode(',', $dependencies));
    }

    public function getGlobalOrRootName($code)
    {
        return preg_replace(
            '/.*(?:window|global|root)\.([a-zA-Z](?:\w|\-)+).*/s',
            '$1',
            $code
        );
    }

    public function globalOrRoot($code)
    {
        return preg_match(
            '/(?:window|global|root)\.[a-zA-Z](?:\w|\-)+\s+\=/',
            $code
        );
    }

    public function isModule($code)
    {
        $ng = $this->isNg($code);

        $module = preg_match(
            '/angular[ \n]*\.[ \n]*module[ \n]*\([ \n]*[\'"]{1}[a-zA-Z0-9\.-]+[\'"]{1}[ \n]*,[ \n]*\[/',
            $code
        );

        return $ng || $module;
    }

    public function isNg($code)
    {
        return preg_match(
            '/angularModule[ \n]*\([ \n]*[\'"]{1}ng[\'"]{1}/',
            $code
        );
    }

    public function getModuleName($code)
    {
        return preg_replace(
            '/.*angular[ \n]*\.[ \n]*module[ \n]*\([ \n]*[\'"]{1}([a-zA-Z0-9\.-]+).*/s',
            '$1',
            $code
        );
    }
}
