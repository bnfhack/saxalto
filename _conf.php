<?php
ini_set('display_errors', '1');
error_reporting(-1);
return array(
  "srcdir" => dirname(__FILE__)."/alto/", // dossier des altos zippés
  "altolist" => dirname(__FILE__)."/altolist.txt", // liste des fichiers à traiter
  "sqlite" => "notices.sqlite", // optionnel, base de métadonnées sqlite des notices crées avec xmarctools
  "destdir" => dirname(__FILE__)."/tei/", // dossier de destination des notices
  "publisher" => "TGB (BnF – OBVIL)", // nom d’éditeur pour les métadonnées XML/TEI
  "threads" => 5, // nombre de process à lancer
);
?>
