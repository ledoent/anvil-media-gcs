<?php
require '/app/vendor/autoload.php';
require '/app/src/StreamWrapper.php';
require '/app/src/Storage.php';
use Ledoent\AnvilMediaGcs\Storage;
use Ledoent\AnvilMediaGcs\StreamWrapper;

$s = new Storage('meas-duropc-media', 'meas-inst-prod');
$s->register_stream_wrapper();
$B = 'gs://meas-duropc-media/verify/2026/08';
$ok=0; $fail=0;
function check($n,$fn){ global $ok,$fail; printf("%-40s",$n);
  try{ $r=$fn(); if($r===true||is_string($r)){echo (is_string($r)?$r:"PASS")."\n"; $ok++;} else {echo "FAIL\n"; $fail++;} }
  catch(Throwable $e){ echo "ERROR ".substr($e->getMessage(),0,70)."\n"; $fail++; } }

check("write through our wrapper", fn()=> file_put_contents("$B/a.txt","anvil")!==false);
check("read back", fn()=> file_get_contents("$B/a.txt")==='anvil');
check("url_stat: file exists", fn()=> file_exists("$B/a.txt"));
check("url_stat: missing file is false", fn()=> file_exists("$B/nope.txt")===false);
check("url_stat: prefix seen as dir", fn()=> is_dir("$B"));
check("filesize correct", fn()=> filesize("$B/a.txt")===5);

// The point of the whole subclass: cost of distinct-path stats.
StreamWrapper::flush_stat_cache(); clearstatcache();
$t0=microtime(true); for($i=0;$i<10;$i++){ file_exists("$B/miss-$i.txt"); }
$cold=round((microtime(true)-$t0)*1000);
$t0=microtime(true); for($i=0;$i<10;$i++){ file_exists("$B/miss-$i.txt"); }
$warm=round((microtime(true)-$t0)*1000);
printf("%-40s%dms cold -> %dms cached (%d entries)\n","10 distinct stats (collision loop)",$cold,$warm,StreamWrapper::stat_cache_size());
check("cache actually served", fn()=> $warm < $cold ? "PASS ({$warm}ms vs {$cold}ms)" : false);

$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9QzzCKRsEoGgWjAAAcFwEB0j0LqQAAAABJRU5ErkJggg==');
file_put_contents("$B/i.png",$png);
check("getimagesize on stream", function() use($B){ $x=@getimagesize("$B/i.png"); return $x? "PASS {$x[0]}x{$x[1]}":false; });
check("copy to local temp (EXIF path)", function() use($B){ $t=tempnam(sys_get_temp_dir(),'a'); $r=copy("$B/i.png",$t); $sz=filesize($t); unlink($t); return $r && $sz===79 ? "PASS ($sz bytes)":false; });
check("unlink", function() use($B){ unlink("$B/a.txt"); StreamWrapper::flush_stat_cache(); clearstatcache(); return !file_exists("$B/a.txt"); });

echo "\n". str_repeat("-",56) ."\n";
printf("passed: %d   failed: %d\n", $ok, $fail);
exit($fail>0?1:0);
