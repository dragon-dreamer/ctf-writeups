<?php
class FuncBody {
	public function __construct($name) {
		$this->name = $name;
	}
	
	public function getValueForNextCall($name, $tables) {
		for ($i = 0, $cnt = count($this->lines); $i !== $cnt; ++$i) {
			$line = $this->lines[$i];
			if (strpos($line, 'call    walk_' . $name) !== false) {
				if (($char = $this->tryToReverseJumpTo($i)) !== null) {
					return $char;
				}
				if (($char = $this->tryToReverseSwitch($i, $tables)) !== null) {
					return $char;
				}
				break;
			}
		}
		
		die('Unable to determine jump value for ' . $name . ' from ' . $this->name);
	}
	
	private function tryToReverseSwitch($lineIndex, $tables) {
		if (!$lineIndex) {
			return null;
		}
		$jumpAddr = $lineIndex - 1;
		if (!preg_match('/text:([0-9A-F]{16}) /', $this->lines[$jumpAddr], $m)) {
			return null;
		}
		$jumpAddr = hexdec($m[1]);
		
		$sub = null;
		$table = null;
		for ($i = 0, $cnt = count($this->lines); $i !== $cnt; ++$i) {
			$line = $this->lines[$i];
			if (preg_match('/sub\s*eax, ([0-9A-F]+?)h/', $line, $m)) {
				if ($sub !== null) {
					die ('duplicate sub inside ' . $this->name);
				}
				$sub = hexdec($m[1]);
			} else if (preg_match('/lea\s*rax, unk_(.+?)$/', $line, $m)) {
				if ($table !== null) {
					die ('duplicate table inside ' . $this->name);
				}
				$table = $m[1];
			}
		}
		
		if ($sub === null || $table === null) {
			return null;
		}
		
		if (!isset($tables[$table])) {
			die ('unknown table ' . $table);
		}
		
		$offsetIndex = $tables[$table]->getIndexForOffset($jumpAddr);
		return chr($offsetIndex + $sub);
	}
	
	private function tryToReverseJumpTo($lineIndex) {
		if ($lineIndex > 2 && preg_match('/loc_(.+?):$/', $this->lines[$lineIndex - 2], $m)) {
			$lineIndex -= 3;
			$loc = $m[1];
			while ($lineIndex > 0) {
				if (strpos($this->lines[$lineIndex], 'jz      short loc_' . $loc) !== false) {
					if (preg_match('/cmp\s*eax, ([0-9A-F]+)h/', $this->lines[$lineIndex - 1], $m)) {
						return chr(hexdec($m[1]));
					}
				}
				--$lineIndex;
			}
		}
		
		if ($lineIndex > 2 && preg_match('/jnz\s+short loc_/', $this->lines[$lineIndex - 2], $m)) {
			if (preg_match('/cmp\s*eax, ([0-9A-F]+)h/', $this->lines[$lineIndex - 3], $m)) {
				return chr(hexdec($m[1]));
			}
		}
		
		return null;
	}
	
	public $extCalls = [];
	public $name;
	public $lines = [];
}

class Table {
	public function __construct($name, $tableOffset) {
		$this->name = $name;
		$this->tableOffset = $tableOffset;
	}
	
	public function putByte($value) {
		$this->currOffset |= ($value << $this->offsetCount * 8);
		if (++$this->offsetCount === 4) {
			$this->offsets[($this->currOffset + $this->tableOffset) & 0xffffffff] = $this->offsetIndex++;
			$this->offsetCount = 0;
			$this->currOffset = 0;
		}
	}
	
	public function finish() {
		if ($this->offsetCount !== 0) {
			die ('unfinished table ' . $this->name);
		}
	}
	
	public function getIndexForOffset($offset) {
		if (!isset($this->offsets[$offset])) {
			die('offset ' . $offset . ' does not exist in table ' . $this->name);
		}
		return $this->offsets[$offset];
	}
	
	public $offsets = [];
	public $name;
	private $tableOffset;
	private $currOffset = 0;
	private $offsetIndex = 0;
	private $offsetCount = 0;
}

$f = array_map('trim', explode("\n", file_get_contents('chall.lst')));

$funcBodies = [];
$funcBody = null;
$endFuncBody = null;
$tables = [];
$table = null;
foreach ($f as $line) {
	if (strpos($line, 'public walk_') !== false) {
		if ($funcBody !== null) {
			die('error: no func end');
		}
		if (!preg_match('/public walk_(.+?)$/', $line, $m)) {
			die ('error parsing func name');
		}
		if (!isset($funcBodies[$m[1]])) {
			$funcBodies[$m[1]] = new FuncBody($m[1]);
		}
		$funcBody = $funcBodies[$m[1]];
		$endFuncBody = 'walk_' . $m[1] . ' endp';
	} else if ($endFuncBody !== null && strpos($line, $endFuncBody) !== false) {
		$funcBody = null;
		$endFuncBody = null;
	} else if ($funcBody !== null) {
		$funcBody->lines[] = $line;
		if (strpos($line, 'call    walk_') !== false) {
			if (!preg_match('/call    walk_(.+?)$/', $line, $m)) {
				die ('error parsing func call');
			}
			if (!isset($funcBodies[$m[1]])) {
				$funcBodies[$m[1]] = new FuncBody($m[1]);
			}
			$funcBody->extCalls[$m[1]] = $funcBodies[$m[1]];
		}
	} else if (preg_match('/unk_(.+?)\s*db/', $line, $m)) {
		$table = $tables[$m[1]] = new Table($m[1], hexdec($m[1]));
	}
	
	if ($table !== null && preg_match('/rodata:.+? db\s+([0-9A-F]{1,5})h?/', $line, $m)) {
		$table->putByte(hexdec($m[1]));
	}
}
foreach ($tables as $table) {
	$table->finish();
}

$queue = new SplQueue;
$from = 'start';
$to = 'end';
$queue->enqueue($funcBodies[$from]);
$visited = [$from => $funcBodies[$from]];
$pathFound = false;
while (!$queue->isEmpty()) {
	$func = $queue->dequeue();
	if ($func->name === $to) {
		$pathFound = true;
		break;
	}
	
	foreach ($func->extCalls as $call) {
		if (!isset($visited[$call->name])) {
			$visited[$call->name] = $func;
			$queue->enqueue($call);
		}
	}
}

if (!$pathFound) {
	die('no path');
}

echo 'PATH FOUND: ' . PHP_EOL;
$jumpValues = [];
$name = $func->name;
while ($name !== $from) {
	echo $name . ' <- ';
	$prev = $visited[$name];
	$jumpValues[] = $prev->getValueForNextCall($name, $tables);
	$name = $prev->name;
}
echo $name . PHP_EOL . PHP_EOL;

echo 'SOLUTION: ' . PHP_EOL;
$jumpValues = array_reverse($jumpValues);
array_map(fn($val) => print($val . ' '), $jumpValues);