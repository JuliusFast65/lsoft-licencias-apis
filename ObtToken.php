<?php

function ObtToken($serie,  $indice, $frase) {


    if (empty($frase)) 
       $frase = 'Yo solO sE que NadA se dIjo SocRaTes en El AnIo 453 antes De Cristo';
    
      /*echo("<br>serie = $serie<br>"); 
      echo("<br>indice = $indice<br>"); 
      echo("<br>frase = $frase<br>"); */
    
    
    // CONSTRUYO UNA MATRIZ DE 10X10 CON SECUENCIAS DE CARACTERES BASADOS EN UNA FRASE
    $frase = str_replace(' ', '', $frase);
    $long = strlen($frase);
    $m = 0;
    $n = $long - 1;
    $l = 0;
    for ($i = 0; $i < 10; $i++) {
        for ($j = 0; $j < 10; $j++) {
            $Matriz[$i][$j] = '';
            $c = ObtDigito(substr($frase, $l, 1));
            if (++$l == $long)        
               $l = 0;
            for ($k = 0; $k < $c; $k++) {
                $Matriz[$i][$j] = $Matriz[$i][$j] . substr($frase, $m, 1) . substr($frase, $n, 1);
                if (--$n < 0)
                   $n = $long - 1;
                if (++$m == $long)
                   $m = 0;
             }
        }
    }
    
    /* CONSTRUYO EL TOKEN PARA LA SERIE Y EL INDICE RECIBIDOS
     TOMANDO ELEMENTOS DE LA MATRIZ ANTERIOR */
    $token = '';
    $long = strlen($serie);
    $indice = intval($indice);
    for ($i = 0; $i < $long; $i++) {
        $j = intval(substr($serie, $i, 1));
        $token = $token . $Matriz[$indice][$j];
        if (++$indice == 10)
           $indice = 0;
    }
    
    return $token;
}

function ObtDigito($caracter) {
    $ascii = strval(ord($caracter));
    $t = 0;
    for ($i = 0; $i < strlen($ascii); $i++) 
        $t = $t + intval(substr($ascii, $i, 1));
    if ($t > 9) {
        $ts = strval($t);
        $t = 0;
        for ($i = 0; $i < strlen($ts); $i++) 
            $t = $t + intval(substr($ts, $i, 1));
    }

    return $t;
}


?>