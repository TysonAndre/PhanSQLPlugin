<?php

$ora = new Ora();
echo strlen($ora->getSelectRows('SELECT count(*) over() as my_column FROM misc where 1=1'));
