<?php
declare(strict_types=1);

// Revisa si el usuario tiene acceso a una funcionalidad premium.
// Se usa para activar funciones como el tutor de IA o contenido exclusivo.
function user_has_feature(PDO $pdo,int $userId,string $feature): bool {
    $q=$pdo->prepare("SELECT p.features FROM user_subscriptions s JOIN subscription_plans p ON p.id=s.plan_id WHERE s.user_id=? AND s.status='active' AND s.starts_at<=CURDATE() AND (s.ends_at IS NULL OR s.ends_at>=CURDATE()) AND p.is_available=1");$q->execute([$userId]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $raw){$features=json_decode((string)$raw,true);if(!is_array($features))$features=array_map('trim',explode(',',(string)$raw));if(in_array($feature,$features,true))return true;}
    return false;
}
