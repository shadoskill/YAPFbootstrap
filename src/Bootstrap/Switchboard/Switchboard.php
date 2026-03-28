<?php

namespace YAPF\Bootstrap\Switchboard;

use YAPF\Bootstrap\ConfigBox\BootstrapConfigBox;
use YAPF\Bootstrap\Template\View;
use YAPF\Framework\Helpers\FunctionHelper;

abstract class Switchboard extends FunctionHelper
{
    protected BootstrapConfigBox $config;

    protected string $targetEndpoint = "";
    protected ?View $loadedObject;

    protected string $loadingModule = "";
    protected string $loadingArea = "";
    protected string $defaultModule = "Home";
    protected string $defaultArea = "DefaultView";

    public function __construct()
    {
        global $system;
        $this->config = $system;
        $this->loadPage();
    }

    protected function accessChecks(): bool
    {
        return true;
    }

    protected function notSet(?string $input): bool
    {
        if (($input === "") || ($input === null)) {
            return true;
        }
        return false;
    }

    protected function findMasterClass(): ?string
    {
        $routes = [
            [$this->loadingArea],
            [],
            [$this->loadingArea, $this->config->getPage()],
            [$this->loadingArea, $this->config->getPage(), $this->config->getOption()],
            [$this->defaultArea],
        ];
        foreach ($routes as $route) {
            $bits = array_merge(["App", "Endpoint", $this->targetEndpoint, $this->loadingModule], $route);
            $use_class = "\\" . implode("\\", $bits);
            if (class_exists($use_class) == false) {
                continue;
            }
            return $use_class;
        }
        return null;
    }

    protected function loadPage(): void
    {
        $this->loadingModule = $this->config->getModule();
        $this->loadingArea = $this->config->getArea();

        if ($this->notSet($this->loadingModule) == true) {
            $this->loadingModule = $this->defaultModule;
        }

        if ($this->accessChecks() == false) {
            $this->addError("failed checks");
            http_response_code(400);
            print json_encode([
                "status" => "0",
                "message" => "badly formated request",
            ]);
            return;
        }
        if (in_array($this->loadingArea, ["", "*"]) == true) {
            $this->loadingArea = $this->defaultArea;
        }
        $use_class = $this->findMasterClass();
        if ($use_class === null) {
            $this->addError("Unsupported request");
            http_response_code(501);
            print json_encode([
                "status" => false,
                "message" => "[" . $this->loadingModule . " | "
                    . $this->loadingArea . " | " . $this->config->getPage() . "] Unsupported",
            ]);
            return;
        }

        $this->loadedObject = new $use_class();
        if ($this->loadedObject->getLoadOk() == true) {
            $this->finalize();
        }
        $this->loadedObject->getoutput();
        $statussql = $this->loadedObject->getOutputObject()->getSwapTagBool("status");

        if ($statussql === false) {
            $this->config->getSQL()->flagError();
            return;
        }
        $this->config->shutdown();
        if ($this->config->getCallWaitFor() == true) {
            if (function_exists('fastcgi_finish_request')) {
                // this is a hack to force the server to send the response to the client
                // while allowing stuff to happen in the background
                fastcgi_finish_request();
            }
            $this->loadedObject->waitFor();
        }
    }

    protected function finalize(): void
    {
        $this->loadedObject->getOutputObject()->setSwapTag("module", $this->loadingModule);
        $this->loadedObject->getOutputObject()->setSwapTag("area", $this->loadingArea);
        $this->loadedObject->process();
    }
}
