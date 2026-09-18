<?php

declare(strict_types=1);

function fs_subscriber_retention_days(PDO $pdo, string $key, int $default, int $minimum, int $maximum): int
{
    try{$st=$pdo->prepare('SELECT svalue FROM app_settings WHERE skey=? LIMIT 1');$st->execute([$key]);$value=$st->fetchColumn();$days=$value!==false?(int)$value:$default;}catch(Throwable $e){$days=$default;}
    return max($minimum,min($maximum,$days));
}

function fs_subscriber_retention_cutoff(int $days): string
{
    return date('Y-m-d H:i:s',time()-$days*86400);
}

/** @return array<string,int> */
function fs_subscriber_retention_run(PDO $pdo): array
{
    $challengeDays=fs_subscriber_retention_days($pdo,'subscriber_retention_challenge_days',7,1,90);
    $challengeRecordDays=fs_subscriber_retention_days($pdo,'subscriber_retention_challenge_record_days',30,$challengeDays,365);
    $networkDays=fs_subscriber_retention_days($pdo,'subscriber_retention_network_context_days',30,1,365);
    $trustedDays=fs_subscriber_retention_days($pdo,'subscriber_retention_trusted_device_days',90,7,730);
    $auditDays=fs_subscriber_retention_days($pdo,'subscriber_retention_audit_origin_days',30,1,365);
    $identifierDays=fs_subscriber_retention_days($pdo,'subscriber_retention_inactive_identifier_days',180,30,730);
    $authAttemptDays=fs_subscriber_retention_days($pdo,'subscriber_retention_auth_attempt_days',30,1,365);

    $st=$pdo->prepare("UPDATE subscriber_login_challenges SET target_encrypted='PURGED',code_hash=SHA2(CONCAT(code_hash,':purged'),256),origin_hash=NULL,updated_at=NOW() WHERE created_at<? AND target_encrypted<>'PURGED'");$st->execute([fs_subscriber_retention_cutoff($challengeDays)]);$purgedChallenges=$st->rowCount();
    $st=$pdo->prepare('DELETE FROM subscriber_login_challenges WHERE created_at<?');$st->execute([fs_subscriber_retention_cutoff($challengeRecordDays)]);$deletedChallenges=$st->rowCount();
    $st=$pdo->prepare('UPDATE subscriber_access_grants SET device_mac=NULL,device_ip=NULL,failure_detail=NULL,updated_at=NOW() WHERE COALESCE(ended_at,created_at)<? AND (device_mac IS NOT NULL OR device_ip IS NOT NULL OR failure_detail IS NOT NULL)');$st->execute([fs_subscriber_retention_cutoff($networkDays)]);$purgedNetwork=$st->rowCount();
    $st=$pdo->prepare('UPDATE subscriber_audit SET origin_hash=NULL WHERE created_at<? AND origin_hash IS NOT NULL');$st->execute([fs_subscriber_retention_cutoff($auditDays)]);$purgedOrigins=$st->rowCount();
    $st=$pdo->prepare('DELETE FROM subscriber_trusted_devices WHERE (revoked_at IS NOT NULL OR expires_at<=NOW()) AND updated_at<?');$st->execute([fs_subscriber_retention_cutoff($trustedDays)]);$deletedTrusted=$st->rowCount();
    $st=$pdo->prepare('DELETE FROM subscriber_device_identifiers WHERE active=0 AND updated_at<?');$st->execute([fs_subscriber_retention_cutoff($identifierDays)]);$deletedIdentifiers=$st->rowCount();
    $deletedAuthAttempts=0;
    try{$st=$pdo->prepare('DELETE FROM subscriber_auth_attempts WHERE created_at<?');$st->execute([fs_subscriber_retention_cutoff($authAttemptDays)]);$deletedAuthAttempts=$st->rowCount();}catch(Throwable $e){/* Compatibilidade durante a aplicação da migração 039. */}
    return ['challenges_purged'=>$purgedChallenges,'challenge_records_deleted'=>$deletedChallenges,'network_context_purged'=>$purgedNetwork,'audit_origins_purged'=>$purgedOrigins,'trusted_devices_deleted'=>$deletedTrusted,'inactive_identifiers_deleted'=>$deletedIdentifiers,'auth_attempts_deleted'=>$deletedAuthAttempts];
}
