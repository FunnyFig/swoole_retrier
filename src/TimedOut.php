<?php

namespace FunnyFig\Swoole;

class TimedOut extends \Exception {
	function __construct(string $msg="", int $code=0, Throwable $prev=null)
	{
		parent::__construct($msg, $code, $prev);
	}
}

