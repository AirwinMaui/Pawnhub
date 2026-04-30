<?php
// Test if exec is available and tesseract works
echo "exec exists: " . (function_exists('exec') ? 'YES' : 'NO') . "\n";
echo "shell_exec exists: " . (function_exists('shell_exec') ? 'YES' : 'NO') . "\n";
echo "proc_open exists: " . (function_exists('proc_open') ? 'YES' : 'NO') . "\n";

$out = []; $ret = 0;
exec('/usr/bin/tesseract --version 2>&1', $out, $ret);
echo "tesseract exec ret: $ret\n";
echo implode("\n", $out) . "\n";

$shell = shell_exec('/usr/bin/tesseract --version 2>&1');
echo "shell_exec result: " . ($shell ?: 'EMPTY') . "\n";