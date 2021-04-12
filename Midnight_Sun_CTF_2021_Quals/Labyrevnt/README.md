# Perverted Labyrevnt solution

Here I present probably the most unusual and crazy way to solve this task :see_no_evil:.

_You are given a binary file (named `chall`) with many similar `walk_***` functions, which call each other based on user input.  These functions read a character from the input and decide the next call based on this character. You have to find the input, which will finally transfer execution to a function, which returns the non-zero value._ This will make the `main` function output the flag:

![Main code decompiled](https://github.com/dragon-dreamer/ctf-writeups/Midnight_Sun_CTF_2021_Quals/Labyrevnt/main-decompiled.png)

One of the functions, which returns `1`, is the `walk_end` function. So, basically, we are required to find a path from `walk_start` to `walk_end`, as well as the sequence of input characters, which make the code follow this path.

Let's export the listing file for the binary `chall` from IDA Pro. This file basically contains all the functions and data from the binary in a human-readable format. We can now parse this file and get the required information. All of the `walk_***` function, which we are going to parse later, are very similar to each other. There are only three options of how the execution control may be transferred to another function:

1. Based on `jz` condition check, such as this one:
   
   ![JZ](https://github.com/dragon-dreamer/ctf-writeups/blob/main/Midnight_Sun_CTF_2021_Quals/Labyrevnt/jz.png)
   
   The `jz` instruction is always preceded by the `cmp` instruction, which essentially tells the character required to follow this condition.
   
2. Based on `jnz` condition check, such as the following one:
   
   ![JNZ](https://github.com/dragon-dreamer/ctf-writeups/blob/main/Midnight_Sun_CTF_2021_Quals/Labyrevnt/jnz.png)
   
   The `jnz` instruction is also always preceded by the `cmp` instruction, as in the previous case. We get the input character in the same way here.
   
3. Based on the optimized switch-case with the transition table stored as data. For example:
   
   ![Switch-case](https://github.com/dragon-dreamer/ctf-writeups/blob/main/Midnight_Sun_CTF_2021_Quals/Labyrevnt/switch-case.png)
   
   Here some value is first subtracted from the `eax` register, and then the switch-case transition table is read from the `unk_71244` offset (this offset is different for each function, as each function has its own table), and then the jump offset is calculated using this table and the `eax` value. After the offset is calculated and corrected (in case the binary is relocated), the code jumps to it using the `jmp rax` instruction. To determine, which `eax` value leads the function call we are looking for, we need to look at the offsets of the switch-case jump table. Offsets are stored at the indexes from `0` to some positive value, and the `eax` value indicates, which index will be used. So we'll need to find the offset index from the offset, which essentially gives us the `eax` value after subtraction.

Now we understand the job which we need to do. We need to parse all of the `walk_***` functions and the `unk_***` switch-case tables for them. Then, we'll find a path from the `walk_start` to the `wak_end` function. And finally, we'll determine the input characters, which we need to pass to the executable to make it follow this path.

To do this, I will write the script in `PHP` :satisfied:.

I'll create two helper classes `FuncBody` and `Table` to store the `walk_***` function bodies and the switch-case jump tables, respectively:

```php
//Class to store the disassembly of the function body named walk_$name
class FuncBody {
	public function __construct($name) {
		$this->name = $name;
	}
	
	//This function will be used to get the eax value, which must be passed to input
	//to make this function call another function with name walk_$name.
	public function getValueForNextCall($name, $tables) {
		//Enumerate all lines of this function body
		for ($i = 0, $cnt = count($this->lines); $i !== $cnt; ++$i) {
			$line = $this->lines[$i];
			//If the line is a call to the function we are looking for...
			if (strpos($line, 'call    walk_' . $name) !== false) {
				//Then try to determine the eax value, which will make current
				//function call another walk_$name function.
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
	
	//This function processes two first scenarios (jz and jnz jumps to a function call)
	private function tryToReverseJumpTo($lineIndex) {
		//If there is a loc_*** label two lines before the call we are looking at...
		if ($lineIndex > 2 && preg_match('/loc_(.+?):$/', $this->lines[$lineIndex - 2], $m)) {
			$lineIndex -= 3;
			$loc = $m[1];
			//Then go through all of current function lines and look for the jump to this label.
			while ($lineIndex > 0) {
				//If there is a jump we are looking for...
				if (strpos($this->lines[$lineIndex], 'jz      short loc_' . $loc) !== false) {
					//Then parse the eax value from the line, which precedes this jump. This value is
					//the required input character to make current function call walk_$name!
					if (preg_match('/cmp\s*eax, ([0-9A-F]+)h/', $this->lines[$lineIndex - 1], $m)) {
						return chr(hexdec($m[1]));
					}
				}
				--$lineIndex;
			}
		}
		
		//If there is the "jnz short loc_***" instruction two lines before the call we are looking at...
		if ($lineIndex > 2 && preg_match('/jnz\s+short loc_/', $this->lines[$lineIndex - 2], $m)) {
			//Then parse the eax value from the line, which precedes this jump. This value is
			//the required input character to make this function call walk_$name!
			if (preg_match('/cmp\s*eax, ([0-9A-F]+)h/', $this->lines[$lineIndex - 3], $m)) {
				return chr(hexdec($m[1]));
			}
		}
		
		return null;
	}
	
	//This function processes the third scenario (optimized switch-case)
	private function tryToReverseSwitch($lineIndex, $tables) {
		if (!$lineIndex) {
			return null;
		}
		//Parse the address of the code section, which calls the walk_$name function
		$jumpAddr = $lineIndex - 1;
		if (!preg_match('/text:([0-9A-F]{16}) /', $this->lines[$jumpAddr], $m)) {
			return null;
		}
        //This address must be present in the switch-case jump table for
        //current function
		$jumpAddr = hexdec($m[1]);
		
		$sub = null;
		$table = null;
		for ($i = 0, $cnt = count($this->lines); $i !== $cnt; ++$i) {
			$line = $this->lines[$i];
			//Look for the sub eax, *** line, which will tell us, what value was
			//subtracted from the eax. We will add it later to get the correct input character.
			if (preg_match('/sub\s*eax, ([0-9A-F]+?)h/', $line, $m)) {
				if ($sub !== null) {
					die ('duplicate sub inside ' . $this->name);
				}
				$sub = hexdec($m[1]);
			//Also look for the switch-case jump table offset (unk_***)
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
		
		//Now we have the table offset, find the table
		if (!isset($tables[$table])) {
			die ('unknown table ' . $table);
		}
		
		//Get the offset index from the table by the offset we need current function to jump to.
        //This is the eax value after subtraction.
		$offsetIndex = $tables[$table]->getIndexForOffset($jumpAddr);
		//Add subtracted value to the offset index, and this is the original input character
		//we need to pass to the function to make it call walk_$name function.
		return chr($offsetIndex + $sub);
	}
	
    //List of external calls from current function
	public $extCalls = [];
    //Current function name
	public $name;
    //Disassembly lines for current function
	public $lines = [];
}
```

```php
//Class to store the switch-case jump table data
class Table {
	public function __construct($name, $tableOffset) {
		$this->name = $name;
		$this->tableOffset = $tableOffset;
	}
	
	//This function will be used later to fill the table.
	//It takes care of the chall.lst file format, which has the
	//switch-case jump tables exported byte-by-byte.
	public function putByte($value) {
        //Restore the original address (which has 4 byte size) byte by byte
		$this->currOffset |= ($value << $this->offsetCount * 8);
		if (++$this->offsetCount === 4) {
			$this->offsets[($this->currOffset + $this->tableOffset) & 0xffffffff]
                = $this->offsetIndex++;
			$this->offsetCount = 0;
			$this->currOffset = 0;
		}
	}
	
	//This function checks if the table is complete.
	public function finish() {
		if ($this->offsetCount !== 0) {
			die ('unfinished table ' . $this->name);
		}
	}
	
	//This functions returns the offset index for the $offset.
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
```

Now let's parse the `walk_***` functions and the switch-case jump tables from the `chall.lst` file:

```php
//Read the file and split it to the array line-by-line
$f = array_map('trim', explode("\n", file_get_contents('chall.lst')));

//All of the parsed function bodies
$funcBodies = [];
//Current function we're parsing
$funcBody = null;
//Last line of the current function
$endFuncBody = null;
//All of the parsed switch-case jump tables
$tables = [];
//Current table we're parsing
$table = null;
foreach ($f as $line) {
	//If we encounter the walk_*** function prologue...
	if (strpos($line, 'public walk_') !== false) {
		if ($funcBody !== null) {
			die('error: no func end');
		}
		//then parse its name...
		if (!preg_match('/public walk_(.+?)$/', $line, $m)) {
			die ('error parsing func name');
		}
		//create this function, if it was not already created earlier
		if (!isset($funcBodies[$m[1]])) {
			$funcBodies[$m[1]] = new FuncBody($m[1]);
		}
		$funcBody = $funcBodies[$m[1]];
        //When we encounter this line, this will mean, the function if completed
		$endFuncBody = 'walk_' . $m[1] . ' endp';
	//Otherwise, if we encounter the end of the function we're currently parsing...
	} else if ($endFuncBody !== null && strpos($line, $endFuncBody) !== false) {
		//Stop function parsing and get ready to parse something else
		$funcBody = null;
		$endFuncBody = null;
	} else if ($funcBody !== null) {
		//Otherwise, append the line of disassembly from the lst file to the function body.
		$funcBody->lines[] = $line;
		//If this line is a call to another walk_*** function...
		if (strpos($line, 'call    walk_') !== false) {
			//then parse its name...
			if (!preg_match('/call    walk_(.+?)$/', $line, $m)) {
				die ('error parsing func call');
			}
			//And add this call as an external call to this function
			if (!isset($funcBodies[$m[1]])) {
				$funcBodies[$m[1]] = new FuncBody($m[1]);
			}
			$funcBody->extCalls[$m[1]] = $funcBodies[$m[1]];
		}
	//Otherwise, if this is a switch-case jump table...
	} else if (preg_match('/unk_(.+?)\s*db/', $line, $m)) {
		//Create this table...
		$table = $tables[$m[1]] = new Table($m[1], hexdec($m[1]));
	}
	
	//If we're currently parsing a switch-case jump table,
	//then add a byte to this table.
	if ($table !== null && preg_match('/rodata:.+? db\s+([0-9A-F]{1,5})h?/', $line, $m)) {
		$table->putByte(hexdec($m[1]));
	}
}
//Check all tables are completed
foreach ($tables as $table) {
	$table->finish();
}
```

Okay, now we have all `walk_***` function bodies parsed (with their external calls to another `walk_***` functions). In addition, we have all switch-case jump tables parsed as well.

Now let's run a [breadth first search](https://en.wikipedia.org/wiki/Breadth-first_search) algorithm to find the path from the `walk_start` to the `walk_end` function:

```php
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
```

This is a classical implementation of the BFS algorithm using queue (`SplQueue`). It finds the shortest path very fast. Now we have to traverse this path and to determine, which input characters make functions on this path follow it.

```php
echo 'PATH FOUND: ' . PHP_EOL;
$jumpValues = [];
$name = $func->name;
while ($name !== $from) {
	echo $name . ' <- ';
	$prev = $visited[$name];
	//Get the input character, which makes the $prev function transition to
	//the $name function.
	$jumpValues[] = $prev->getValueForNextCall($name, $tables);
	$name = $prev->name;
}
echo $name . PHP_EOL . PHP_EOL;
```

Now we have the list of characters, which will make our functions follow the required path from `walk_start` to `walk_end`. All that left is to reverse this list (as the path found was initially reversed) and output it:

```php
echo 'SOLUTION: ' . PHP_EOL;
$jumpValues = array_reverse($jumpValues);
array_map(fn($val) => print($val . ' '), $jumpValues);
```

That's all, folks! We can now get the solution in less than a second :blush::

![Switch-case](https://github.com/dragon-dreamer/ctf-writeups/blob/main/Midnight_Sun_CTF_2021_Quals/Labyrevnt/output.png)

See [solve.php](https://github.com/dragon-dreamer/ctf-writeups/blob/main/Midnight_Sun_CTF_2021_Quals/Labyrevnt/solve.php) for the full solution.