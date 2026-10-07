<?php
use skeeks\cms\backend\widgets\BackendSurfaceWidget;
use yii\helpers\Html;
use yii\helpers\Json;
/** @var array $details @var string $detailsUrl @var \skeeks\cms\job\models\CmsJobRun $model */
$id = 'mobile-push-report-'.(int)$model->id;
?>
<section id="<?= Html::encode($id) ?>">
<?php BackendSurfaceWidget::begin(['title' => 'Результат отправки уведомления', 'headerBordered' => true]); ?>
<p><strong data-push-status><?= Html::encode($details['status']) ?></strong></p>
<p data-push-message><?= Html::encode($details['message']) ?></p>
<table class="sx-key-value-view"><tbody data-push-rows>
<?php foreach ($details['rows'] as $label => $value): ?>
<tr><th scope="row"><?= Html::encode($label) ?></th><td><?= Html::encode($value) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p data-push-refresh class="sx-collection-cell__secondary" role="status"></p>
<?php BackendSurfaceWidget::end(); ?>
</section>
<?php
$options = Json::htmlEncode(['id' => (int)$model->id, 'selector' => '#'.$id, 'url' => $detailsUrl, 'active' => $details['active']]);
$this->registerJs(<<<JS
(function(o){
 const root=document.querySelector(o.selector);if(!root||!o.active)return;
 function schedule(){setTimeout(poll,5000);}
 async function poll(){
  if(!root.isConnected)return;if(document.hidden){schedule();return;}
  const abort=new AbortController(),timeout=setTimeout(()=>abort.abort(),15000);
  try{
   const r=await fetch(o.url,{signal:abort.signal,credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
   if(!r.ok)throw Error();const d=(await r.json()).data?.[o.id]?.report;if(!d)throw Error();
   root.querySelector('[data-push-status]').textContent=d.status;root.querySelector('[data-push-message]').textContent=d.message;
   const body=root.querySelector('[data-push-rows]');body.textContent='';
   Object.entries(d.rows).forEach(([label,value])=>{const tr=document.createElement('tr'),th=document.createElement('th'),td=document.createElement('td');th.scope='row';th.textContent=label;td.textContent=value;tr.append(th,td);body.appendChild(tr);});
   root.querySelector('[data-push-refresh]').textContent='';if(d.active)schedule();
  }catch(e){root.querySelector('[data-push-refresh]').textContent='Не удалось обновить сведения. Повторяем запрос…';schedule();}
  finally{clearTimeout(timeout);}
 }
 schedule();
})($options);
JS);
