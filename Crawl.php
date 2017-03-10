<?php
Crawl::docli();
class Crawl {
  static $pb;
  static $log;
  static $dom;
  public static function docli() {
    self::$dom = new DOMDocument('1.0', 'UTF-8');
    self::$log = array();
    $handle = fopen("OBVIL-BNF-crawl.csv", "w");
    fwrite ($handle, "dossier\tproblèmes\tauteur\ttitre\tpages\tark\n");
    foreach (glob("*") as $altodir) {
      if (!is_dir($altodir)) continue;
      $code = basename($altodir);
      self::$pb = array();
      $notice = null;
      $dh = opendir($altodir);
      while (($file = readdir($dh)) !== false) {
        if (!preg_match("/^X[0-9]+\.xml/i", $file)) continue;
        $notice = $altodir . '/' . $file;
      }
      closedir($dh);
      if (!$notice) self::$pb[] = 'notice=0';
      if (file_exists($path = $altodir . '/X')) {
        $alto = count(scandir($path)) - 2;
        $last = 0;
        foreach(glob($altodir . '/X/X*.*') as $srcfile) {
          $basename = basename($srcfile);
          $n = 0 + preg_replace('@[^0-9]@', '', $basename);
          if ($n != ($last + 1)) self::$pb[] = "p." . ($last + 1) . "/" . $n;
          $last = $n;
          // 
          $ext = pathinfo($srcfile, PATHINFO_EXTENSION);
          if ($ext == 'gz') {
            $cont = array();
            $zd = gzopen($srcfile, "r");
            while (!gzeof($zd)) {
              $cont[] = gzread($zd, 50000);
            }
            gzclose($zd);
            $cont = implode($cont, '');
          }
          else {
            $cont = file_get_contents($srcfile);
          }
          $oldError=set_error_handler("Crawl::xmlerror", E_ALL);
          self::$dom->loadXML($cont);
          restore_error_handler();
          if (count(self::$log)) {
            self::$log = array();
            self::$pb[] = $basename;
          }
        }
      }
      else {
        self::$pb[] = "alto=0";
        $alto = 0;
      }
      if (file_exists($path = $altodir . '/T')) {
        $tif = count(scandir($path)) - 2;
      }
      else {
        self::$pb[] = "tif=0";
        $tif = 0;
      }
      $auteur = $titre = $ark = $pages = '';
      if ($notice) {
        $xml = file_get_contents($notice);
        $code = pathinfo (dirname($notice), PATHINFO_FILENAME);
        preg_match_all('@<auteur[^>]*>([^<]+)</auteur>|<titre[^>]*>([^<]+)</titre>|<reference type="NOTICEBIBLIOGRAPHIQUE">([^<]+)</reference>|<nombreImages>([^<]+)</nombreImages>@', $xml, $out, PREG_SET_ORDER);
        foreach ($out as $line) {
          if (isset($line[4]) && $line[4]) $pages .= ' ' . $line[4];
          else if (isset($line[3]) && $line[3]) $ark .= ' ' . $line[3];
          else if (isset($line[2]) && $line[2]) $titre .= ' ' . $line[2];
          else if (isset($line[1]) && $line[1]) $auteur .= ' ' . $line[1];
        }
      }
      if ($tif < 0 + $pages) {
        self::$pb[] = "tif<pages";
      }
      fwrite($handle,  "*" . $code . "\t" . implode(self::$pb, ' ') . "\t" . trim($auteur) . "\t" . preg_replace( '@"@', '\"', trim($titre)) . "\t" . trim($pages) . "\t" . trim($ark) . "\n");
    }
    fclose($handle);
  }
  /** record errors in a log variable, need to be public to used by loadXML */
  public static function xmlerror( $errno, $errstr, $errfile, $errline, $errcontext) {
    if(strpos($errstr, "xmlParsePITarget: invalid name prefix 'xml'") !== FALSE) return;
    self::$log[]=$errstr;
  }
}
?>