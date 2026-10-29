<?php
declare(strict_types=1);

// Genera el enlace de pago para Wompi.
// Si la clave no está configurada, la compra queda pendiente y nunca se cobra.
function wompi_checkout_url(PDO $pdo,int $planId,int $userId): string {
    $public=(string)getenv('PROSSOR_WOMPI_PUBLIC_KEY');$integrity=(string)getenv('PROSSOR_WOMPI_INTEGRITY_SECRET');
    if($public===''||$integrity==='')throw new RuntimeException('Checkout Wompi no está configurado. La solicitud queda sin cobro.');
    $q=$pdo->prepare('SELECT id,name,price_cop FROM subscription_plans WHERE id=? AND is_available=1');$q->execute([$planId]);$plan=$q->fetch();
    if(!$plan||(int)$plan['price_cop']<=0)throw new RuntimeException('El plan no tiene precio vigente configurado.');if((int)$plan['price_cop']>intdiv(4294967295,100))throw new RuntimeException('El monto excede el máximo permitido por el formato de la orden.');
    $pdo->beginTransaction();
    try{$pdo->prepare("INSERT INTO user_subscriptions(user_id,plan_id,status,starts_at) VALUES(?,?,'pending',CURDATE())")->execute([$userId,$planId]);$sid=(int)$pdo->lastInsertId();$ref='PROSSOR-'.bin2hex(random_bytes(16));$amount=(int)$plan['price_cop']*100;$environment=getenv('PROSSOR_WOMPI_ENV')==='prod'?'prod':'test';
      $pdo->prepare('INSERT INTO payment_orders(user_id,subscription_id,reference,environment,amount_in_cents) VALUES(?,?,?,?,?)')->execute([$userId,$sid,$ref,$environment,$amount]);
      $signature=hash('sha256',$ref.$amount.'COP'.$integrity);$params=['public-key'=>$public,'currency'=>'COP','amount-in-cents'=>$amount,'reference'=>$ref,'signature:integrity'=>$signature,'redirect-url'=>app_base_url().'/pagos/suscripciones.php'];
      $pdo->commit();return 'https://checkout.wompi.co/p/?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
