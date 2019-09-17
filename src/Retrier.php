<?php

namespace FunnyFig\Swoole;

use chan;

require_once 'vendor/autoload.php';
use FunnyFig\Swoole\Timer;

class Retrier {
	protected const MIN_WAIT_MS = 10;
	protected const MAX_WAIT_MS = 100;

	protected $proc;
	protected $timeout;

	protected $chan;
	protected $sw;
	protected $n_tries = 0;
	protected $timer;

	static function try(callable $proc, int $timeout=3000, $nchan=false)
	{
		return new Retrier($proc, $timeout, $nchan);
	}

	function chan()
	{
		return $this->chan;
	}

	function stop()
	{
		$this->timer->stop();
	}

	protected function __construct(callable $proc, int $timeout, $nchan)
	{
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
			// FIXME: push exception
			$this->chan->push(new TimedOut());
			$this->timer->stop();
			return 0;
		}

		$min = min( (int)(self::MIN_WAIT_MS* 1.5 ** $this->n_tries++)
			  , self::MAX_WAIT_MS);
		$max = min($min*2, self::MAX_WAIT_MS);

		return min($ms_left, random_int($min, $max));
	}
}





//if (!debug_backtrace()) {
//	$t = Retrier::try(function () {
//		static $count = 0;
//		if ($count++ == 10) {
//			return 'found';
//		}
//		return "routine";
//	});
//
//	$sw = new StopWatch();
//	$sw->start();
//
//	go(function () use($t, $sw) {
//		// pop return false
//		while (!(($rv = $t->chan()->pop()) instanceof Throwable)) {
//			if ($rv === 'found') {
//				$t->stop();
//				break;
//			}
//			echo $sw->lap().": $rv\n";
//		}
//	});
//}


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
