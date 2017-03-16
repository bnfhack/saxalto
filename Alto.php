<?php  // encoding="UTF-8"
set_time_limit(-1);

Alto::init();
$test = new Alto( "../test/1025076.zip" );
// dest file, folder is suffix
// CBA/GFEDCBA.xml

class Alto {
  /** Log level */
  public $loglevel = E_ALL;
  /** A logger, maybe a stream or a callable, used by $this->log() */
  private $_logger;
  /** DOM Document */
  private static $_dom;
  /** 1) un processeur XSLT, compilation des pages  */
  private static $_alto2work;
  /** 2) programme de recherche/remplacement */
  private static $_preg = array(
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
  /** 3) transformeur xslt du format pivot vers TEI */
  private static $_work2tei;


  /**
   * Charger un fichier alto en zip
   */
  function __construct( $altozip, $logger="php://output" )
  {
    if ( is_string($logger) ) $logger = fopen($logger, 'w');
    $this->_logger = $logger;
    $altoid = pathinfo( $altozip, PATHINFO_BASENAME );

    $zip = new ZipArchive();
    $res = $zip->open( $altozip ); //stocker le code erreur en cas d’échec
    if ( $res !== TRUE) {
      $this->log( E_USER_ERROR, "ERREUR > unzip $altozip, code: $res" );
      return;
    }
    else {
      $this->log( E_USER_NOTICE, "\textraction de $altozip" );
      $entries = array();
      // ramasser les entrées pour les trier
      for ( $i=0 ; $i < $zip->numFiles ; $i++ ) {
        $row = $zip->statIndex( $i );
        if ( $row['size'] == 0 ) continue; // dossier
        $entries[] = $row['name'];
      }
      sort( $entries );
      $xml = array();
      $xml[] = '<book xmlns="http://www.tei-c.org/ns/1.0">';
      // print_r( $entries )."\n";
      foreach ( $entries as $path ) {
        // $oldError=set_error_handler("Alto::err", E_ALL );
        // ?? quelles erreurs ?
        self::$_dom->loadXML( $zip->getFromName( $path ) );
        // restore_error_handler();
        $xml[] = self::$_alto2work->transformToXml( self::$_dom );
      }
      $xml[] = '</book>';
      $zip->close();
    }
    $xml = implode( "\n", $xml );
    $xml=preg_replace( array_keys( self::$_preg ), array_values( self::$_preg ), $xml );
    echo $xml;
  }


  /**
   * Command line interface for the class
   */
  public static function alto2tei() {
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

  /**
   * Charger les objets statiques
   */
  static function init()
  {
    self::$_dom = new DOMDocument( '1.0', 'UTF-8' );
    self::$_dom->load( dirname(__FILE__).'/alto2work.xsl' );
    self::$_alto2work = new XSLTProcessor();
    self::$_alto2work->registerPHPFunctions();
    self::$_alto2work->importStyleSheet( self::$_dom );
    self::$_dom->load( dirname(__FILE__).'/work2tei.xsl' );
    self::$_work2tei = new XSLTProcessor();
    self::$_work2tei->registerPHPFunctions();
    self::$_work2tei->importStyleSheet( self::$_dom );
  }

  /** record errors in a log variable, need to be public to used by loadXML */
  public static function err( $errno, $errstr, $errfile, $errline, $errcontext) {
    if(strpos($errstr, "xmlParsePITarget: invalid name prefix 'xml'") !== FALSE) return;
    self::$log[]=$errstr;
  }

  /**
   * Custom error handler
   * May be used for xsl:message coming from transform()
   * To avoid Apache time limit, php could output some bytes during long transformations
   */
  function log( $errno, $errstr, $errfile=null, $errline=null, $errcontext=null)
  {
    if ( !$this->loglevel & $errno ) return false;
    $errstr=preg_replace("/XSLTProcessor::transform[^:]*:/", "", $errstr, -1, $count);
    /* ?
    if ( $count ) { // is an XSLT error or an XSLT message, reformat here
      if ( strpos($errstr, 'error') !== false ) return false;
      else if ( $errno == E_WARNING ) $errno = E_USER_WARNING;
    }
    */
    if ( !$this->_logger );
    else if ( is_resource($this->_logger) ) fwrite( $this->_logger, $errstr."\n");
    else if ( is_string($this->_logger) && function_exists( $this->_logger ) ) call_user_func( $this->_logger, $errstr );
  }


}
?>
