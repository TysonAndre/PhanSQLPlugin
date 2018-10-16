<?php

$ora = new Ora();
echo strlen($ora->getSelectRows('select distinct(foo) from table', []));
echo strlen($ora->getSelectRows('select distinct(foo) from table'));
