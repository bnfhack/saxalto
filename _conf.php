<?php
ini_set('display_errors', '1');
error_reporting(-1);
$proj = "";
return array(
  "xmldir" => "/home/fred/Documents/rougemont/DDR/ocr/",
  "altolist" => "/home/fred/Documents/rougemont/DDR/altolist.txt", // liste des fichiers à traiter
  // "sqlite" => "notices.sqlite", // optionnel, base de métadonnées sqlite des notices crées avec xmarctools
  "destdir" => "/home/fred/Documents/rougemont/DDR/tei/", // dossier de destination des notices
  // "publisher" => "TGB (BnF – OBVIL)", // nom d’éditeur pour les métadonnées XML/TEI
  "threads" => 5, // nombre de process à lancer
);
?>
