<?php



  $config = [
    "container" => $container,
    "paths"     => [
      "base_uri" => rtrim($_SERVER['REQUEST_URI'], '/')
    ]
  ];

  return $config;
?>