<?php
/** Tests d’installation isolés, sans réseau ni données de l’instance d’origine. */
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/ytdl-community-test-'.bin2hex(random_bytes(8));mkdir($temp,0700,true);
putenv('YTDL_DATA_DIR='.$temp.'/private');putenv('YTDL_LIBRARY_ROOT='.$temp.'/library');$checks=0;
function check(bool $condition,string $message): void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;echo "OK : $message\n";}
function runPhp(array $args): array {$p=proc_open(array_merge([PHP_BINARY],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);return [proc_close($p),$out,$err];}
function cleanup(string $path): void {if(is_dir($path)){foreach(new FilesystemIterator($path) as $item)cleanup($item->getPathname());rmdir($path);}else unlink($path);}
try {
 require $root.'/db.php';$db=ytdl_db();
 check((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'Aucun compte personnel par défaut');
 check(YTDL_DATA_DIR===realpath($temp.'/private'),'Base isolée');check(YTDL_VAPID_PRIVATE_KEY==='','Aucune clé privée préinstallée');
 $args=[$root.'/scripts/setup.php','--name=Community Admin','--email=admin@example.org','--url=http://localhost:8080/ytdl'];
 [$status,$out,$err]=runPhp($args);check($status===0,'Initialisation CLI : '.trim($err));
 $users=$db->query('SELECT email,is_admin,status FROM users')->fetchAll(PDO::FETCH_ASSOC);
 check(count($users)===1&&$users[0]['email']==='admin@example.org'&&(int)$users[0]['is_admin']===1&&$users[0]['status']==='active','Un seul administrateur explicitement choisi');
 check(is_file($temp.'/library/.ytdl-library-volume'),'Bibliothèque indépendante initialisée');
 check(is_file(YTDL_DATA_DIR.'/vapid-config.php')&&(fileperms(YTDL_DATA_DIR.'/vapid-config.php')&0077)===0,'Clés neuves avec permissions privées');
 [$status]=runPhp($args);check($status!==0&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===1,'Réinitialisation refusée sans changement de compte');
 require $root.'/lib.php';require $root.'/mailer.php';
 check(ytdl_library_volume_mounted(),'Marqueur reconnu');check(ytdl_base_url()==='http://localhost:8080/ytdl','URL publique configurable');check(ytdl_jobs_root()===YTDL_DATA_DIR.'/jobs','Tâches dans le stockage privé');
 file_put_contents(YTDL_DATA_DIR.'/smtp-config.php','<?php return '.var_export(['host'=>'smtp.example.org','port'=>587,'username'=>'example','password'=>'test-only','from'=>'no-reply@example.org'],true).';');
 check(ytdl_smtp_config()['from']==='no-reply@example.org','SMTP privé reconnu sans envoi');
 [$status]=runPhp(['-r','putenv("YTDL_DATA_DIR=".$argv[1]); require $argv[2];',$root,$root.'/config.php']);
 check($status!==0,'Stockage de données sous le site refusé');
 [$status]=runPhp(['-r','putenv("YTDL_LIBRARY_ROOT=relative-library"); require $argv[1];',$root.'/config.php']);
 check($status!==0,'Chemin de bibliothèque relatif refusé');
 [$status]=runPhp(['-r','putenv("YTDL_LIBRARY_ROOT=".$argv[1]); require $argv[2];',$root,$root.'/config.php']);
 check($status!==0,'Bibliothèque sous le site refusée');
 echo "$checks vérifications réussies.\n";
}finally{$db=null;cleanup($temp);}
