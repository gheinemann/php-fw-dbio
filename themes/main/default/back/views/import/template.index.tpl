{include file="includes/template.head.tpl"}

<h1>{$content.h1}</h1>

<br/><br/>

<a class="button button-primary js-import-launcher" href="#" data-dbio='{$content.dbio|@json_encode}' data-stop-element=".js-import-stopper" data-report-element="#dbio_report">Lancer !</a>
<a class="button button-primary js-import-stopper" href="#">STOP</a>


{include file="includes/template.footer.tpl"}