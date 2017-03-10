<?php  // encoding="UTF-8"
/**
 * Different tools to transform TEI files 
 */
// cli usage
set_time_limit(-1);
if (php_sapi_name() == "cli") {
  Alto::doCli();
} 

class Alto {
  static $debug;
  static $dom;
  static $alto2work;
  static $work2tei;
  static $preg;
  static $log = array();
  public static function docli() {
    array_shift($_SERVER['argv']); // shift first arg, the script filepath
    if(!count($_SERVER['argv'])) {
      echo 'php -f Alto.php "srcdir/*" destdir/';
      return;
    }
    $glob=array_shift($_SERVER['argv']);
    if(!count($_SERVER['argv'])) {
      $destdir = '_out/';
    }
    else {
      $destdir = array_shift($_SERVER['argv']);
      $destdir = rtrim($destdir, '/\\') . '/';
    }
    if (!file_exists($destdir)) mkdir($destdir, null, true);


    self::$dom = new DOMDocument('1.0', 'UTF-8');
    self::$dom->load(dirname(__FILE__).'/alto2work.xsl');
    self::$alto2work = new XSLTProcessor();
    self::$alto2work->registerPHPFunctions();
    self::$alto2work->importStyleSheet(self::$dom);
    self::$dom->load(dirname(__FILE__).'/work2tei.xsl');
    self::$work2tei = new XSLTProcessor();
    self::$work2tei->registerPHPFunctions();
    self::$work2tei->importStyleSheet(self::$dom);
    
    self::$preg =   array(
      // '@</(p|ab)>\s*<item>@' => '</$1>'."\n".'<list type="dialogue">'."\n".'<item>',
      // '@</item>\s*(<(/page|p|ab)[ |>])@' => '</item>'."\n".'</list>'."\n".'$1', // avant <fw> etc.
      '@(<page.*>)\n<p[^>]*>\n(?:<tt>)?([0-9]+).*\n</p>@'=>'$1'."\n".'<fw>$2</fw>', // titre courant page paire
      '@(<page.*>)\n<p[^>]*>\n[^0-9]+([0-9]+)(?:</tt>)?\n</p>@'=>'$1'."\n".'<fw>$2</fw>', // titre courant page impaire
      '@[iI]+\. *[0-9]+(\s*</p>\s*</page>)@' => '$1', // n° de feuillet
      '@(?<=«)([^»\n]*\n(<[^>]+>)?)(?=«)@u' => '$1µµµ', // marquer les guillemets ouvrants en début de ligne précédés d’un guillemet ouvrant, avec assertions pour tromper le pointeur
      '@µµµ«@' => '', // supprimer les guillemets marqués ci-dessus
      '@(<small>) +@' => '$1',
      '@(\n) +@' => '$1', // espace laissé par les césures résolues
      '@-</small>\n<small>([^ ]*)@' => '$1'."\n", // césure, raccrocher balises de lignes
      '@</small>\n<small>@' => "\n", // raccrocher balises de lignes
      '@</(b|i|u|sc|sub)>(\s*)<\1>@' => '$2',
      '@\.\.\.@' => '…',
      '@([cCdDjJlLmMnNsStT]|qu|Qu)\'@u' => '$1’',
      '@([«]) @u' => '$1 ', // rendre insécable les espaces existants
      '@([«])([^ ])@u' => '$1 $2', // espace insécable après guillemets
      '@ ([;:!?»])@u' => ' $1', // rendre insécable les espaces avant ponctuation double
      '@\)([^. ])@u' => ') $1',
      '@([^ ])([;!?»])@u' => '$1 $2', // Attention à ':' dans les URI et ';' dans les entités
      '@(&#?[a-zA-Z0-9]+) ;@' => '$1;', // protect entities
      '@( [;\?!])</(i|sup)>@u' => '</$2>$1', // certaines ponctuations hors ital
      '@([,.])</(i|sup)>@u' => '</$2>$1', // certaines ponctuations hors ital
      '@<sup>Mme</sup>@' => 'M<sup>me</sup>',
      '@ae@' => 'æ',
      '@oe@' => 'œ',
      '@A[Ee]@' => 'Æ',
      '@O[Ee]@' => 'Œ',
      '@<small>\s*<i>([0-9]+\.?)</i>@' => '<small>$1', // n° de note à libérer
      '@<sup>in-([0-9]+)°</sup>@' => 'in-$1°',
      '@<sup>([0-9IVXVLCMxvi]+)(er?|[èe]re)</sup>@u' => '<num>$1<sup>$2</sup></num>',
      // pb Vie
      // '@ vie([ ,\.])@' => ' µµµvieµµµ$1', // protéger la vie de ci-dessous
      // '@ ([ivxIVX]+)e([ ,\.])@' => ' <num>$1<sup>e</sup></num>$2', // siècles
      // '@µµµvieµµµ@' => 'vie', // restaurer la vie
        // <sup>plus1 ?…</sup> <sup>1:</sup>
        // surligner les mots avec chiffres, ou avec apostrophe finale (note) élevé"[;,]
      '@<i>([\(])@' => '$1<i>', // sortir parenthèse ouvrante
      '@<p[^>]*>\s*<big>(.*)</big>\s*</p>@u' => "<h1>$1</h1>", // titres de section ?
      '@<p[^>]*>\n([IVXLC]+\.?)\n</p>@' => '<h2>$1</h2>', // titre de section en chiffres romains
      '@<p[^>]*>\n([0-9A-ZÉÈÀÇŒÆ\'’]+)\n</p>@u' => '<h1>$1</h1>', // titres de page en capitales
      '@</h([1-6])>\s*<h(\1)>@' => ' ',
      '@<h([1-6])>@' => '<head n="$1">',
      '@</h([1-6])>@' => '</head>',
    );
    $tr = array(
      'à'=>'a', 
      'é'=>'e', 
      'è'=>'e', 
      'ë'=>'e', 
      'ê'=>'e', 
      'î'=>'i', 
      'ï'=>'i', 
      '_'=>'-', 
      '_'=>'-', 
      ' ' => '-',
      "'" => '-',
      '"' => '',
      '(' => '',
      ')' => '',
      // ' ' => '',
      ',' => '',
      '.' => '',
      ':' => '',
      '/'=>'-', 
      '&'=>'et', 
      '!' => '',
      '?' => '',
    );

    foreach(glob($glob) as $altodir) {
      if (!is_dir($altodir)) continue;
      if (!is_dir($altodir . '/X')) {
        echo "$altodir X/ ? NO ALTO\n";
        continue;
      }
      if (file_exists($f = $altodir . '/X' . basename($altodir) . '.xml'));
      else if (file_exists($f = $altodir . '/X' . basename($altodir) . '.XML'));
      else $f = '';
      $destfile = $destdir . basename($altodir) . '.xml';
      if ($f) {
        $xml = file_get_contents($f);
        preg_match_all('@<auteur[^>]*>([^<]+)</auteur>|<titre[^>]*>([^<]+)</titre>|<reference type="NOTICEBIBLIOGRAPHIQUE">([^<]+)</reference>|<nombreImages>([^<]+)</nombreImages>@', $xml, $out, PREG_SET_ORDER);
        $auteur = $titre = $ark = $pages = '';
        foreach ($out as $line) {
          if (isset($line[4]) && $line[4]) $pages .= ' ' . $line[4];
          else if (isset($line[3]) && $line[3]) $ark .= ' ' . $line[3];
          else if (isset($line[2]) && $line[2]) $titre .= ' ' . $line[2];
          else if (isset($line[1]) && $line[1]) $auteur .= ' ' . $line[1];
        }
        $auteur = trim($auteur) . "(";
        $auteur = trim(substr($auteur, 0, strpos($auteur, "("))) . ",";
        $auteur = trim(substr($auteur, 0, strpos($auteur, ",")));
        $auteur = mb_convert_case($auteur, MB_CASE_LOWER, "UTF-8");
        $auteur = strtr($auteur, $tr);
        
        $titre = explode(" ", trim($titre), 4);
        array_pop($titre);
        $titre = implode($titre, '-');
        $titre = mb_convert_case($titre, MB_CASE_LOWER, "UTF-8");
        $titre = strtr($titre, $tr);
        $destfile = $destdir . $auteur . '_' . $titre . '_' . basename($altodir) . '.xml';
      }
      echo $altodir . ' > ' . $destfile . "\n";
      self::alto2tei($altodir, $destfile);
    }
  }
  /** record errors in a log variable, need to be public to used by loadXML */
  public static function err( $errno, $errstr, $errfile, $errline, $errcontext) {
    if(strpos($errstr, "xmlParsePITarget: invalid name prefix 'xml'") !== FALSE) return;
    self::$log[]=$errstr;
  }

  /**
   * Command line interface for the class 
   */
  public static function alto2tei($altodir, $destfile) {
    $altodir = rtrim($altodir, '/\\') . '/';
    $buf = array();
    foreach(glob($altodir . 'X/X*.*') as $srcfile) {
      // self::$dom->load("compress.zlib://$srcfile"); // *.xml.gz
      $oldError=set_error_handler("Alto::err", E_ALL);
      self::$dom->load( realpath($srcfile));
      restore_error_handler();
      if (count(self::$log)) {
        echo "    " . implode(self::$log, "\n    "), "\n";
        self::$log = array();
        continue;
      }
      $xml=self::$alto2work->transformToXml(self::$dom);
      $buf[]=$xml;
    }
    $xml=implode("\n", $buf);
    $xml=preg_replace(array_keys(self::$preg), array_values(self::$preg), $xml);
    $xml='<?xml version="1.0" encoding="UTF-8"?>
<?xml-stylesheet type="text/xsl" href="../../../lib/teipub/tei2html.xsl"?>
<?xml-stylesheet type="text/css" href="http://svn.code.sf.net/p/algone/code/teibook/teibook.css"?>
<TEI xmlns="http://www.tei-c.org/ns/1.0" xml:lang="fr">
  <text>
    <body>
'.$xml.'
    </body>
  </text>
</TEI>';
    // echo $xml;
    $oldError=set_error_handler("Alto::err", E_ALL);
    self::$dom->loadXML($xml);
    restore_error_handler();
    if (count(self::$log)) {
      echo "    " . implode(self::$log, "\n    "), "\n";
      self::$log = array();
    }
    $xml=self::$work2tei->transformToXml(self::$dom);
    $preg=array(
      '@<\?div\?>@'=>'<div>', // écrire les <div>
      '@<\?div /\?>@'=>'</div>', // écrire les </div>
      '@</p>\s*(<pb[^>]*/>)\s*<p[^>]*>\s*(\p{Ll})@u' => "\n".'$1$2', // raccrocher les paragraphes autour des sauts de page
      '@(<note xml:id="[^"]+">)\s*[0-9]+\.\s+@' => '$1'."\n", // retirer les n° des notes reconnues7
      '@ +(<note)@' => '$1', // coller l’appel de note
    );
    $xml=preg_replace(array_keys($preg), array_values($preg), $xml);
    file_put_contents($destfile, $xml);
  }
  
  

}
?>