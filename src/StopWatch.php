<?php

namespace FunnyFig\Swoole;

class StopWatch {
	protected $start;

	function start()
	{
		$this->start = microtime(true);
	}

	function lap()
	{
		return \intval((microtime(true)-$this->start)*1e3);
	}

	function stop()
	{
		$rv = $this->lap();
		$this->start = null;
		return $rv;
	}
}

