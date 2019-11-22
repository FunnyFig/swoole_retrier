<?php

namespace FunnyFig\Swoole;

use chan;

require_once 'vendor/autoload.php';
use FunnyFig\Swoole\Timer;

class Retrier {
	protected const DEF_MIN_WAIT_MS = 10;
	protected const DEF_MAX_WAIT_MS = 100;
	protected $min_wait_ms;
	protected $max_wait_ms;

	protected $proc;
	protected $timeout;

	protected $chan;
	protected $sw;
	protected $n_tries = 0;
	protected $timer;

	static function try(callable $proc, int $timeout=3000, $nchan=false, $opt=[])
	{
		return new Retrier($proc, $timeout, $nchan, $opt);
	}

	function chan()
	{
		return $this->chan;
	}

	function stop()
	{
		$this->timer->stop();
	}

	protected function __construct(callable $proc, int $timeout, $nchan, $opt)
	{
		$this->min_wait_ms = $opt['min'] ?? self::DEF_MIN_WAIT_MS;
		$this->max_wait_ms = $opt['max'] ?? self::DEF_MAX_WAIT_MS;
		if ( $this->min_wait_ms <= 0
		  || $this->max_wait_ms <= 0
		  || $this->min_wait_ms > $this->max_wait_ms)
		{
			throw new \InvalidArgumentException();
		}

		$this->proc = $proc;
		$this->timeout = $timeout;

		$this->chan = !$nchan? new chan(1)
			    : new class() {
				    function push() {}
				    function pop() {}
			    };

		$this->sw = new StopWatch();
		$this->sw->start();

		$this->timer = new Timer( function() { $this->proc(); }
					, 0
					, function() { return $this->next(); });
	}

	protected function proc()
	{
		try {
			$rv = call_user_func($this->proc, $this);
		}
		catch (Throwable $t) {
			$rv = $t;
		}

		$this->chan->push($rv);
	}

	protected function next()
	{
		$ms_left = $this->timeout - $this->sw->lap();

		if ($ms_left <= 0) {
			$this->chan->push(new TimedOut());
			$this->timer->stop();
			return 0;
		}

		$min = min( (int)($this->min_wait_ms* 1.5 ** $this->n_tries++)
			  , $this->max_wait_ms);
		$max = min($min*2, $this->max_wait_ms);

		return min($ms_left, random_int($min, $max));
	}
}

if (!debug_backtrace()) {
	$t = Retrier::try(function ($t) {
		static $count = 0;
		if ($count++ == 10) {
			echo "found\n";
			$t->stop();
			return;
		}
		echo "routine\n";
	}, 3000, true);
}
