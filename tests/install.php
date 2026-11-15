<?php
/** Tests d’installation isolés, sans réseau ni données de l’instance d’origine. */
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/ytdl-community-test-'.bin2hex(random_bytes(8));mkdir($temp,0700,true);
putenv('YTDL_DATA_DIR='.$temp.'/private');putenv('YTDL_LIBRARY_ROOT='.$temp.'/library');$checks=0;
function check(bool $condition,string $message): void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;echo "OK : $message\n";}
function runPhp(array $args): array {$p=proc_open(array_merge([PHP_BINARY],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);return [proc_close($p),$out,$err];}
function cleanup(string $path): void {if(is_dir($path)){foreach(new FilesystemIterator($path) as $item)cleanup($item->getPathname());rmdir($path);}else unlink($path);}
try {
 require $root.'/db.php';$db=ytdl_db();
 check($db->query('SELECT quota_bytes FROM users LIMIT 1') !== false,'Colonne de quota présente sur base neuve');
 check($db->query('SELECT playlist_id FROM playback_state LIMIT 1') !== false,'Colonne de playlist de reprise présente sur base neuve');
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
 foreach(['library_access','track_recommendations','import_jobs','import_items'] as $table)check($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn()!==false,"Table $table présente sur base neuve");
 check(ytdl_detect_playlist_platform('https://youtube.com/playlist?list=PLabc123&si=x')==='youtube','Lien de playlist YouTube reconnu');
 check(ytdl_detect_playlist_platform('https://music.youtube.com/watch?v=abc&list=PLabc123')==='ytmusic','Titre YouTube Music ouvert depuis une playlist reconnu');
 check(ytdl_detect_playlist_platform('https://www.youtube.com/playlist?list=WL')===null&&ytdl_detect_playlist_platform('https://youtube.com.example.org/playlist?list=PLabc')===null,'Listes privées et hôtes détournés refusés');
 [$status]=runPhp([$root.'/import-worker.php','999']);check($status===0,'Processus d’import : travail inconnu ignoré sans erreur');
 require $root.'/cookies-lib.php';$cookies="# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSAPISID\tx\n.autre-site.example\tTRUE\t/\tTRUE\t0\tSID\ty\n";
 check(ytdl_cookies_filter($cookies)==="# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSAPISID\tx\n",'Cookies : seuls ceux de YouTube et Google sont gardés');
 check(ytdl_cookies_has_auth($cookies)&&!ytdl_cookies_has_auth("# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tVISITOR_INFO1_LIVE\tz\n"),'Cookies : un export sans connexion est refusé');
 require $root.'/genre-lib.php';
 check($db->query('SELECT genre, genre_source, genre_checked_at FROM download_history LIMIT 1')!==false,'Colonnes de genre présentes sur base neuve');
 check($db->query("SELECT artist_key, genre, source FROM artist_genres LIMIT 1")!==false,'Cache des genres par artiste présent sur base neuve');
 check(ytdl_genre_clean('  rap   français ')==='Rap français'&&ytdl_genre_clean('')===null&&ytdl_genre_clean(str_repeat('x',41))===null,'Nom de genre saisi nettoyé et borné');
 check(ytdl_genre_parse('Artiste - Titre (Official Video)','')[0]==='Artiste'&&ytdl_genre_parse('Sans séparateur','Chaîne - Topic')[0]==='Chaîne'&&ytdl_genre_parse('A & B - Titre','')[0]==='A'&&ytdl_genre_same_name('The Beatles','Beatles'),'Artiste extrait du titre, noms d’artistes comparés');
 [$status]=runPhp([$root.'/genre-worker.php']);check($status===0,'Classement des genres : aucun titre à traiter, sortie sans erreur');
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
