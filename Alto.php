<?php  // encoding="UTF-8"
set_time_limit(-1);
// charge conf.php et configure la base sqlite
Alto::init();
// si pas d’arguments, démarre les threads
if (php_sapi_name() == "cli") {
  array_shift($_SERVER['argv']); // shift first arg, the script filepath
  if (!count($_SERVER['argv'])) exit('  php Alto.php threads
    liste les fichiers pointés dans conf.php["srcdir"] et lance les threads
');
  //   php Alto.php $n
  // $n numéro de thread et modulo des fichiers traités dans la liste

  $action = array_shift($_SERVER['argv']);
  $destdir = dirname(__FILE__);
  if ( isset( Alto::$conf['destdir'] ) ) $destdir = Alto::$conf['destdir'];
  $destir= rtrim( $destdir, "\\/ ")."/";

  // mettre à jour les <teiHeader> avec une nouvelle base
  if ( $action == "upheader" ) {
    Alto::upheader( $destdir );
  }
  else if ( $action == "threads" ) {
    if ( !isset( Alto::$conf['srcdir'] ) ) die( "conf.php['srcdir'] ?\n" );
    $writer = fopen( Alto::$conf['altolist'], "w" );
    Alto::listfiles( Alto::$conf['srcdir'], $writer );
    fclose( $writer );
    for( $i=0; $i < Alto::$conf['threads'] ; $i++ ) {
      exec( "php ".__FILE__." $i > $i.log &" );
      // popen( "php ".__FILE__." $i ", "r" );
    }
  }
  else if ( is_numeric( $action ) ) {
    $reader = fopen( Alto::$conf['altolist'], "r" );
    $modulo = Alto::$conf['threads'];
    $i = 0;
    while ( ( $line = fgets( $reader ) ) !== false ) {
      $i++;
      if ( ( ($i-1) % $modulo) != $action ) continue;
      $id = pathinfo( trim($line), PATHINFO_FILENAME );
      $destfile =  $destdir.substr( $id, -3 )."/".$id.".xml";
      echo $action.'-'.($i-1).' '.$destfile."\n";
      $alto = new Alto( trim($line) );
      $alto->tei( $destfile );
      echo " — DONE\n";
    }
    fclose( $reader );
  }
  else if ( file_exists( $action ) ) {
    $alto = new Alto( $action );
    $destfile =  $destdir.substr($alto->id, -3)."/".$alto->id.".xml";
    echo $destfile."\n";
    $alto->tei( $destfile );
  }
  else {
    die( $action." — action inconnue\n" );
  }
}


class Alto {
  /** Log level */
  public $loglevel = E_ALL;
  /** Identifiant Gallica du fichier en cours de traitement */
  public $id;
  /** XML de travail, transformable */
  private $_xml;
  /** A logger, maybe a stream or a callable, used by $this->log() */
  private $_logger;
  /** Paramètres de configuration obtenus par un fichier conf.php */
  public static $conf;
  /** lien à une base pour les métadonnées */
  private static $_pdo;
  /** Requêtes préparées */
  private static $_q;
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
    // '@ae@' => 'æ',
    // '@oe@' => 'œ',
    // '@A[Ee]@' => 'Æ',
    // '@O[Ee]@' => 'Œ',
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
    $id = pathinfo( $altozip, PATHINFO_BASENAME );

    $zip = new ZipArchive();
    $res = $zip->open( $altozip ); //stocker le code erreur en cas d’échec
    if ( $res !== TRUE) {
      $this->log( E_USER_ERROR, "ERREUR > unzip $altozip, code: $res" );
      return;
    }
    else {
      $this->log( E_USER_NOTICE, " — extraction de $altozip" );
      $entries = array();
      // ramasser les entrées pour les trier
      for ( $i=0 ; $i < $zip->numFiles ; $i++ ) {
        $row = $zip->statIndex( $i );
        if ( $row['size'] == 0 ) { // probablement un dossier
          // si le nom de dossier est un nombre, probablement l'identifiant gallica
          if ( 0+$row['name'] && ($id !== 0+$row['name']) ) { // attention !==
            $id = 0+$row['name'];
          }
          continue;
        }
        $entries[] = $row['name'];
      }
      sort( $entries );
      $buf = array();
      $buf[] = '<book xmlns="http://www.tei-c.org/ns/1.0">';
      // print_r( $entries )."\n";
      foreach ( $entries as $path ) {
        // $oldError=set_error_handler("Alto::err", E_ALL );
        // ?? quelles erreurs ?
        self::$_dom->loadXML( $zip->getFromName( $path ) );
        // restore_error_handler();
        // obligé de transformer page après page, sinon le dom de la totale fera sauter la banque
        $buf[] = self::$_alto2work->transformToXml( self::$_dom );
      }
      $buf[] = '</book>';
      $zip->close();
    }
    unset( $zip );
    $this->id = $id;
    $this->_xml = implode( "\n", $buf );
    unset( $buf );
    $this->_xml = preg_replace( array_keys( self::$_preg ), array_values( self::$_preg ), $this->_xml  );
  }

  /**
   *
   */
  static function upheader( $path )
  {
    if ( is_dir( $path ) ) {
      $path = rtrim( $path, "\\/ ")."/";
      $dh = opendir( $path);
      while ( ($file = readdir($dh) ) !== false) {
        if ( $file[0] == '.' ) continue;
        self::upheader( $path.$file );
      }
      closedir($dh);
      return;
    }
    else if ( is_file( $path ) ) {
      $info = pathinfo( $path );
      if ( $info['extension'] != 'xml' ) return;
      $id = $info['filename'];
      $xml = file_get_contents( $path );
      $from = strpos( $xml, "<teiHeader>" );
      $to = strpos( $xml, $s="</teiHeader>" ) + strlen( $s );
      if ( !$from || !$to || $to < $from  ) {
        return ( print( "<teiHeader/> not found ".$from." ".$to." in ".$path."\n" ) );
      }
      $header = self::teiheader( $id );
      if ( !$header ) {
        return print( "NO RECORD in ".self::$conf['sqlite']." for ".$path."\n");
      }
      file_put_contents( $path, substr( $xml, 0, $from ).$header.substr( $xml, $to ) );
    }
  }

  static function teiheader( $id )
  {
    $xml = array();
    $xml[] = '<teiHeader>';
    if ( self::$_q ) {
      self::$_q['gallica']->execute( array( $id ) );
      $doc = self::$_q['gallica']->fetch();
      // si pas trouvé on laisse partir les erreurs
      $ark = $doc['docark'];
      $xml[] = '<fileDesc>';
      $xml[] = '<titleStmt>';
      $xml[] = '<title>'.$doc['title'].'</title>';
      self::$_q['author']->execute( array( $doc['docid'] ) );
      while ( $person = self::$_q['author']->fetch() ) {
        self::$_q['role']->execute( array( $person['role'] ) );
        $role = self::$_q['role']->fetch();
        $date = "";
        if ( $person['date'] ) $date = ' ('.$person['date'].')';
        $given = "";
        if ( $person['given'] ) $given = ", ".$person['given'];
        if ( $person['isauthor'] ) {
          $xml[] = '<author role="'.$role['label'].'" key="'.$person['id'].'">'.$person['family'].$given.$date.'</author>';
        }
        else {
          $xml[] = '<respStmt>
  <resp key="'.$role['id'].'">'.$role['label'].'</resp>
  <name key="'.$person['id'].'">'.$person['family'].$given.$date.'</name>
</respStmt>';
        }
      }
      // récupérer les auteurs
      $xml[] = '</titleStmt>';
      $xml[] = '<publicationStmt>';
      $xml[] = '<publisher>'.self::$conf['publisher'].'</publisher>';
      $xml[] = '</publicationStmt>';
      if ( $doc['volumes'] > 1 ) {
        $xml[] = '<seriesStmt>';
        $xml[] = '<title level="s">'.$doc['title'].'</title>';
        if ( $doc['galltitle'] ) $xml[] = '<title level="a">'.$doc['galltitle'].'</title>';
        $xml[] = '<biblScope unit="volumes" n="'.$doc['volumes'].'"/>';
        $xml[] = '<idno>'.$doc['docark'].'</idno>';
        $xml[] = '</seriesStmt>';
      }
      $xml[] = '<sourceDesc>';
      $xml[] = '<bibl>';
      $xml[] = '<idno>http://gallica.bnf.fr/ark:/12148/'.$doc['gallark'].'</idno>';
      $xml[] = '<publisher>'.$doc['publisher'].'</publisher>';
      $xml[] = '<date when="'.$doc['year'].'">'.$doc['date'].'</date>';
      $xml[] = '</bibl>';
      $xml[] = '</sourceDesc>';
      $xml[] = '</fileDesc>';
    }
    $xml[] = '</teiHeader>';
    return implode("\n", $xml );
  }

  /**
   * Command line interface for the class
   */
  public function tei( $destfile=null )
  {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>
<TEI xmlns="http://www.tei-c.org/ns/1.0" xml:lang="fr" n="'.$this->id.'">
'.teiheader( $this->id ).'
  <text>
    <body>
'.$this->_xml.'
    </body>
  </text>
</TEI>';
    // LIBXML_NOENT | LIBXML_NONET | LIBXML_NSCLEAN | LIBXML_NOCDATA | LIBXML_NOWARNING
    // $oldError=set_error_handler("Alto::err", E_ALL);
    $previous_value = libxml_use_internal_errors(TRUE);
    if ( !self::$_dom->loadXML( $xml ) ) {
      $this->log( E_USER_ERROR, "ERREUR XML avec ".$this->id." (".$destfile.")\n");
      return false;
    }
    $errors = libxml_get_errors();
    foreach ($errors as $error) {
      if ( $error->code == 68 ); // ??? pas compris
      else if ( $error->code == 539 )
        $this->log( E_USER_WARNING, "ark notice introuvable " );
      else {
        $this->log( E_USER_WARNING, trim( $error->message )." l. ".$error->line);
      }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous_value);
    $xml = self::$_work2tei->transformToXml( self::$_dom ) ;
    $preg=array(
      '@<\?div\?>@'=>'<div>', // écrire les <div>
      '@<\?div /\?>@'=>'</div>', // écrire les </div>
      '@</p>\s*(<pb[^>]*/>)\s*<p[^>]*>\s*(\p{Ll})@u' => "\n".'$1$2', // raccrocher les paragraphes autour des sauts de page
      '@(<note xml:id="[^"]+">)\s*[0-9]+\.\s+@' => '$1'."\n", // retirer les n° des notes reconnues7
      '@ +(<note)@' => '$1', // coller l’appel de note
    );
    $xml=preg_replace(array_keys($preg), array_values($preg), $xml);
    if ( $destfile != null ) {
      if (!is_dir( dirname( $destfile ) ) ) {
        mkdir( dirname( $destfile ), 0775, true );
        @chmod( dirname( $destfile ), 0775 );  // let @, if www-data is not owner but allowed to write
      }
      file_put_contents($destfile, $xml);
    }
    return $xml;
  }

  /**
   * Charger les objets statiques
   */
  static function init()
  {
    if ( !file_exists( $f=dirname( __FILE__ ).'/conf.php' ) ) {
      die( "conf.php ? Renommez _conf.php et changez les paramètres nécessaires à votre convenance.\n" );
    }
    self::$conf = include( $f );
    self::$_dom = new DOMDocument( '1.0', 'UTF-8' );
    self::$_dom->preserveWhiteSpace = false;
    self::$_dom->formatOutput=true;
    self::$_dom->substituteEntities=true;
    self::$_dom->load( dirname(__FILE__).'/alto2work.xsl' );
    self::$_alto2work = new XSLTProcessor();
    self::$_alto2work->registerPHPFunctions();
    self::$_alto2work->importStyleSheet( self::$_dom );
    self::$_dom->load( dirname(__FILE__).'/work2tei.xsl' );
    self::$_work2tei = new XSLTProcessor();
    self::$_work2tei->registerPHPFunctions();
    self::$_work2tei->importStyleSheet( self::$_dom );
    if ( isset( self::$conf['sqlite'] ) ) {
      if ( !file_exists( self::$conf['sqlite'] )) exit( $file." doesn’t exist!\n");
      self::$_pdo = new PDO("sqlite:".self::$conf['sqlite'], "charset=UTF-8");
      self::$_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING ); // get error as classical PHP warn
      self::$_pdo->exec("PRAGMA temp_store = 2;"); // store temp table in memory (efficiency)
      self::$_q = array();
      self::$_q['gallica'] = self::$_pdo->prepare( "
SELECT
  document.id as docid,
  document.ark as docark,
  document.title as title,
  document.date as date,
  document.year as year,
  document.publisher as publisher,
  document.volumes as volumes,
  gallica.ark as gallark,
  gallica.title as galltitle,
  gallica.id as gallid
FROM gallica, document WHERE gallica.id = ? AND gallica.document = document.id
      ");
      self::$_q['author'] = self::$_pdo->prepare( "SELECT person.*, contribution.role AS role, contribution.writes AS isauthor FROM person, contribution WHERE contribution.document = ? AND contribution.person = person.id; ");
      self::$_q['role'] = self::$_pdo->prepare( "SELECT * FROM role WHERE id = ?" );
    }
  }

  /**
   * Chercher les chemins des alto.zip
   */
  public static function listfiles( $srcdir, $writer )
  {
    $srcdir = rtrim( $srcdir, "\\/ ")."/";
    $handle = opendir( $srcdir );
    // $altolist
    if ( !$handle ) die( $srcdir." ILLISIBLE\n" );
    while (false !== ($entry = readdir($handle))) {
      if ( $entry[0] == '.' ) continue;
      if ( is_dir( $srcdir.$entry ) ) self::listfiles( $srcdir.$entry, $writer );
      $ext = pathinfo( $entry, PATHINFO_EXTENSION );
      if ( $ext != "zip" ) continue;
      fwrite( $writer, $srcdir.$entry."\n" );
    }
  }

  /** record errors in a log variable, need to be public to used by loadXML */
  public static function err( $errno, $errstr, $errfile, $errline, $errcontext)
  {
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
