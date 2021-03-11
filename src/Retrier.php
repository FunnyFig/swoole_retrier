<?php

namespace FunnyFig\Swoole;

use chan, co;

require_once 'vendor/autoload.php';
use FunnyFig\Swoole\Timer;

class pacemaker_t {
	protected $timeout;
	protected $min;
	protected $max;
	protected $sw;
	protected $n_tries;

	protected $_get_min;
	protected $_get_max;
	protected $_get_next;

	function __construct($timeout, $min, $max)
	{
		if ($timeout < 0) {
			throw new \InvalidArgumentException();
		}

		if ( $min <= 0
		  || $max <= 0
		  || $min > $max)
		{
			throw new \InvalidArgumentException();
		}


		$this->timeout = $timeout;
		$this->min = $min;
		$this->max = $max;

		$this->sw = new StopWatch();
		$this->reset();
	}

	function reset()
	{
		$this->n_tries = 0;
		$this->_get_min = 'calc_min';
		$this->_get_max = 'calc_max';
		$this->_get_next = 'calc_next';
		$this->sw->start();
	}

	function get_n_tries()
	{
		return $this->n_tries;
	}

	function next()
	{
		$ms_left = $this->timeout - $this->sw->lap();
		if ($ms_left <= 0) {
			return -1;
		}

		$rv = $this->{$this->_get_next}();
		++$this->n_tries;
		return $rv;
	}

	protected function calc_next()
	{
		$min = $this->get_min();
		$max = $this->get_max($min);

		if ($min == $max) {
			$this->_get_next = 'const_next';
			return $max;
		}

		return random_int($min, $max);
	}

	protected function const_next()
	{
		return $this->get_min();
	}

	protected function get_max($min)
	{
		return $this->{$this->_get_max}($min);
	}

	protected function calc_max($min)
	{
		$max = $min*2;
		if ($max >= $this->max) {
			$this->_get_max = 'smallest_max';
			return $this->max;
		}

		return $max;
	}

	protected function smallest_max($min)
	{
		return $this->max;
	}

	protected function get_min()
	{
		return $this->{$this->_get_min}();
	}

	protected function calc_min()
	{
		$min = (int)($this->min* 1.5 ** $this->n_tries);
		if ($min >= $this->max) {
			$this->_get_min = 'largest_min';
			return $this->max;
		}

		return $min;
	}

	protected function largest_min()
	{
		return $this->max;
	}
}

class Retrier {
	protected const DEF_MIN_WAIT_MS = 10;
	protected const DEF_MAX_WAIT_MS = 100;
	protected $pacemaker;

	protected $proc;

	protected $chan;

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

	function reset()
	{
		$this->timer->stop(true);
		$this->chan->close();
		if ($this->chan instanceof chan) {
			$this->chan = new chan(1);
		}
		$this->pacemaker->reset();
		$this->timer->launch();
	}

	function get_n_tries()
	{
		return $this->pacemaker->get_n_tries();
	}

	protected function __construct(callable $proc, int $timeout, $nchan, $opt)
	{
		$min_wait_ms = $opt['min'] ?? self::DEF_MIN_WAIT_MS;
		$max_wait_ms = $opt['max'] ?? self::DEF_MAX_WAIT_MS;
		$this->pacemaker = new pacemaker_t($timeout, $min_wait_ms, $max_wait_ms);

		$this->proc = $proc;

		$this->chan = !$nchan? new chan(1)
			    : new class() {
				    function push($v) {}
				    function pop($v) {}
				    function close() {}
			    };

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
		$rv = $this->pacemaker->next();
		if ($rv < 0) {
			$this->chan->push(new TimedOut());
			$this->timer->stop();
			return 0;
		}

		return $rv;
	}
}

if (!debug_backtrace()) {
// reset test
	$count = 0;
	$t = Retrier::try(function ($t) use(&$count) {
		//static $count = 0;
		if ($count++ == 10) {
			echo "found\n";
			$t->stop();
			return;
		}
		echo "routine: {$count}\n";
	}, 3000, true );

	go(function ($t) use(&$count) {
		co::sleep(0.05);
		$count = 0;
		$t->reset();
	}, $t);

// overflow test
	$t = Retrier::try(function ($trier) {
		echo "try...{$trier->get_n_tries()}\n";
	}, 60*60*1000, true, ['min'=>100, 'max'=>10000]);
}
