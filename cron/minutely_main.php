<?php
$host_not_required = TRUE;
include(realpath(dirname(dirname(__FILE__)))."/includes/connect.php");
if ($GLOBALS['process_lock_method'] == "db") {
	include(realpath(dirname(dirname(__FILE__)))."/includes/handle_script_shutdown.php");
}

$script_target_time = 58;
$script_start_time = microtime(true);

if (!empty($argv)) {
	$cmd_vars = $app->argv_to_array($argv);
	if (!empty($cmd_vars['key'])) $_REQUEST['key'] = $cmd_vars['key'];
	else if (!empty($cmd_vars[0])) $_REQUEST['key'] = $cmd_vars[0];
	if (!empty($cmd_vars['print_debug'])) $_REQUEST['print_debug'] = $cmd_vars['print_debug'];
}

if (empty($GLOBALS['cron_key_string']) || $_REQUEST['key'] == $GLOBALS['cron_key_string']) {
	$print_debug = false;
	if (!empty($_REQUEST['print_debug'])) $print_debug = true;
	
	if ($print_debug) echo "<pre>";
	
	$main_loop_running = $app->check_process_running("main_loop_running");
	
	if (!$main_loop_running) {
		if ($GLOBALS['process_lock_method'] == "db") {
			$GLOBALS['app'] = $app;
			$GLOBALS['shutdown_lock_name'] = "main_loop_running";
			$app->set_site_constant($GLOBALS['shutdown_lock_name'], 1);
			register_shutdown_function("script_shutdown");
		}
		
		$blockchains = array();
		$real_games = array();
		$coin_rpcs = array();
		$game_id2real_game_i = array();

		// If block hashing hasn't run for a long time on private blockchain, add some blocks
		$q = "SELECT * FROM blockchains WHERE online=1 AND p2p_mode='none' AND last_hash_time IS NOT NULL AND (".time()."-last_hash_time) > (seconds_per_block*2);";
		$r = $GLOBALS['app']->run_query($q);
		$log_text = "";
		
		while ($db_blockchain = $r->fetch()) {
			if (empty($blockchains[$db_blockchain['blockchain_id']])) $blockchains[$db_blockchain['blockchain_id']] = new Blockchain($app, $db_blockchain['blockchain_id']);
			
			$seconds_to_add = time()-$db_blockchain['last_hash_time'];
			$blocks_to_add = round($seconds_to_add/$db_blockchain['seconds_per_block']);
			if ($print_debug) echo "adding $blocks_to_add\n";
			
			$associated_games = $blockchains[$db_blockchain['blockchain_id']]->associated_games(false);
			
			for ($i=0; $i<$blocks_to_add; $i++) {
				$created_block_id = $blockchains[$db_blockchain['blockchain_id']]->new_block($log_text);
				for ($j=0; $j<count($associated_games); $j++) {
					list($successful, $this_log_text) = $associated_games[$j]->add_block($created_block_id);
					$log_text .= $this_log_text;
				}
				$sim_hash_time = $db_blockchain['last_hash_time']+round(($i/$blocks_to_add)*$seconds_to_add);
				$blockchains[$db_blockchain['blockchain_id']]->set_last_hash_time($sim_hash_time);
			}
		}
		
		// Initial load of all non-private blockchains
		$q = "SELECT * FROM games g JOIN blockchains b ON g.blockchain_id=b.blockchain_id WHERE b.p2p_mode='rpc';";
		$r = $GLOBALS['app']->run_query($q);
		$real_game_i = 0;

		while ($db_real_game = $r->fetch()) {
			$game_id2real_game_i[$db_real_game['game_id']] = $real_game_i;
			if (empty($blockchains[$db_real_game['blockchain_id']])) $blockchains[$db_real_game['blockchain_id']] = new Blockchain($app, $db_real_game['blockchain_id']);
			$real_games[$real_game_i] = new Game($blockchains[$db_real_game['blockchain_id']], $db_real_game['game_id']);
			try {
				$coin_rpcs[$real_game_i] = new jsonRPCClient('http://'.$db_real_game['rpc_username'].':'.$db_real_game['rpc_password'].'@127.0.0.1:'.$db_real_game['rpc_port'].'/');
				$getinfo = $coin_rpcs[$real_game_i]->getinfo();
			}
			catch (Exception $e) {
				$coin_rpcs[$real_game_i] = false;
			}
			
			if ($coin_rpcs[$real_game_i]) {
				$qq = "UPDATE blockchains SET rpc_last_time_connected='".time()."', block_height='".$getinfo['blocks']."' WHERE blockchain_id='".$db_real_game['blockchain_id']."';";
				$rr = $app->run_query($qq);
			}
			$real_game_i++;
		}
		
		for ($real_game_i=0; $real_game_i<count($real_games); $real_game_i++) {
			if ($real_games[$real_game_i]->db_game['game_status'] == "running" && $real_games[$real_game_i]->db_game['always_generate_coins'] == 1) {
				if ($coin_rpcs[$real_game_i]) {
					$q = "SELECT * FROM blocks WHERE game_id='".$real_games[$real_game_i]->db_game['game_id']."' ORDER BY block_id DESC LIMIT 1;";
					$r = $app->run_query($q);

					if ($r->rowCount() > 0) {
						$lastblock = $r->fetch();
						
						$coin_rpcs[$real_game_i]->setgenerate(false);
						$coin_rpcs[$real_game_i]->setgenerate(true);
						if ($print_debug) echo "Started generating coins for ".$real_games[$real_game_i]->db_game['name']."...\n";
					}
				}
			}
		}

		$q = "SELECT * FROM games WHERE game_status='published' AND start_condition='players_joined' AND start_condition_players > 0;";
		$r = $app->run_query($q);
		while ($db_unstarted_game = $r->fetch()) {
			if (!$blockchains[$db_unstarted_game['blockchain_id']]) $blockchains[$db_unstarted_game['blockchain_id']] = new Blockchain($app, $db_unstarted_game['blockchain_id']);
			$unstarted_game = new Game($blockchains[$db_unstarted_game['blockchain_id']], $db_unstarted_game['game_id']);
			$num_players = $unstarted_game->paid_players_in_game();
			if ($num_players >= $unstarted_game->db_game['start_condition_players']) {
				$unstarted_game->start_game();
			}
		}

		$q = "SELECT * FROM games WHERE game_status='published' AND start_condition='fixed_time' AND start_datetime <= NOW() AND start_datetime IS NOT NULL;";
		$r = $app->run_query($q);
		while ($db_unstarted_game = $r->fetch()) {
			if (time() >= strtotime($db_unstarted_game['start_datetime'])) {
				if (!$blockchains[$db_unstarted_game['blockchain_id']]) $blockchains[$db_unstarted_game['blockchain_id']] = new Blockchain($app, $db_unstarted_game['blockchain_id']);
				$unstarted_game = new Game($blockchains[$db_unstarted_game['blockchain_id']], $db_unstarted_game['game_id']);
				$unstarted_game->start_game();
			}
		}

		if ($GLOBALS['outbound_email_enabled']) {
			$q = "SELECT *, TIME_TO_SEC(TIMEDIFF(NOW(), completion_datetime)) AS sec_since_completion FROM games WHERE giveaway_status IN ('public_pay','invite_pay') AND game_status='completed' AND payout_complete=0 AND (payout_reminder_datetime < DATE_SUB(NOW(), INTERVAL 30 MINUTE) OR payout_reminder_datetime IS NULL);";
			$r = $app->run_query($q);

			while ($completed_game = $r->fetch()) {
				$qq = "UPDATE games SET payout_reminder_datetime=NOW() WHERE game_id='".$completed_game['game_id']."';";
				$rr = $app->run_query($qq);
				
				$subject = $completed_game['name']." has finished, please process payouts.";
				$message = "This game finished ".$app->format_seconds($completed_game['sec_since_completion'])." ago. Please log in with your admin account and follow this link to complete the payout: ".$GLOBALS['base_url']."/payout_game.php?game_id=".$completed_game['game_id'];
				$app->mail_async($GLOBALS['rsa_keyholder_email'], $GLOBALS['site_name'], "no-reply@".$GLOBALS['site_domain'], $subject, $message, "", "");
			}
		}

		// Load all running games
		$running_games = array();
		$q = "SELECT * FROM games WHERE game_status IN('published','running');";
		$r = $GLOBALS['app']->run_query($q);
		
		while ($running_game = $r->fetch()) {
			$game_i = count($running_games);
			if (empty($blockchains[$running_game['blockchain_id']])) $blockchains[$running_game['blockchain_id']] = new Blockchain($app, $running_game['blockchain_id']);
			$running_games[$game_i] = new Game($blockchains[$running_game['blockchain_id']], $running_game['game_id']);
			if ($print_debug) echo "Including game: ".$running_game['name']."\n";
			
			// Update user account values
			$qq = "SELECT * FROM users u JOIN user_games ug ON u.user_id=ug.user_id WHERE ug.game_id='".$running_game['game_id']."' ORDER BY u.user_id ASC;";
			$rr = $app->run_query($qq);
			
			while ($db_user = $rr->fetch()) {
				$user = new User($app, $db_user['user_id']);
				$account_value = $user->account_coin_value($running_games[$game_i], $db_user)/pow(10,$running_games[$game_i]->db_game['decimal_places']);
				
				$qqq = "UPDATE user_games SET account_value='".$account_value."' WHERE user_game_id='".$db_user['user_game_id']."';";
				$rrr = $app->run_query($qqq);
			}
		}
		
		$app->delete_unconfirmable_transactions();
		
		if (count($running_games) > 0) {
			try {
				$loop_target_time = $app->get_site_constant("loop_target_time");
				do {
					$loop_start_time = microtime(true);
					
					for ($running_game_i=0; $running_game_i<count($running_games); $running_game_i++) {
						if ($print_debug) echo "\n".$running_games[$running_game_i]->db_game['name']."\n";
						
						if ($running_games[$running_game_i]->db_game['p2p_mode'] == "none") {
							$remaining_prob = round($loop_target_time/$running_games[$running_game_i]->blockchain->db_blockchain['seconds_per_block'], 4);
							$thisgame_loop_start_time = microtime(true);
							do {
								$benchmark_time = microtime(true);
								if ($print_debug) echo "update_db_game() ...";
								$running_games[$running_game_i]->update_db_game();
								if ($print_debug) echo (microtime(true)-$benchmark_time)." sec\n";
								$benchmark_time = microtime(true);
								
								if ($running_games[$running_game_i]->db_game['game_status'] == "running") {
									$last_block_id = $running_games[$running_game_i]->blockchain->last_block_id();
									
									$block_prob = min(1, $remaining_prob);
									$remaining_prob = $remaining_prob-$block_prob;
									$rand_num = rand(0, pow(10,4))/pow(10,4);
									if (!empty($_REQUEST['force_new_block'])) $rand_num = 0;
									
									if ($print_debug) echo $running_games[$running_game_i]->db_game['name']." (".$rand_num." vs ".$block_prob."): ";
									if ($rand_num <= $block_prob) {
										if ($print_debug) echo "FOUND A BLOCK!!\n";
										$txt = $running_games[$running_game_i]->new_block();
										if ($print_debug) echo $txt;
										list($successful, $this_log_text) = $running_games[$running_game_i]->sync($print_debug);
									}
									else {
										if ($print_debug) echo "No block\n";
									}
								}
								else $remaining_prob = 0;
								
								if ($print_debug) echo (microtime(true)-$benchmark_time)." sec\n";
								$benchmark_time = microtime(true);
							}
							while ($remaining_prob > 0 && microtime(true)-$thisgame_loop_start_time < 60);
							
							$running_games[$running_game_i]->blockchain->set_last_hash_time(time());
						}
						if ($print_debug) echo "Apply user strategies...";
						$txt = $running_games[$running_game_i]->apply_user_strategies();
						
						if ($print_debug) {
							echo $txt;
							echo (microtime(true)-$benchmark_time)." sec\n";
						}
						$benchmark_time = microtime(true);
					}
					
					$loop_stop_time = microtime(true);
					$loop_time = $loop_stop_time-$loop_start_time;
					$loop_target_time = max(1, $loop_time);
					$sleep_usec = round(pow(10,6)*($loop_target_time - $loop_time));
					if ($print_debug) echo "script run time: ".(microtime(true)-$script_start_time).", sleeping ".$sleep_usec/pow(10,6)." seconds.\n";
					usleep($sleep_usec);
					$app->set_site_constant("loop_target_time", round($loop_target_time, 4));
				}
				while (microtime(true) < $script_start_time + ($script_target_time-$loop_target_time));
			}
			catch (Exception $e) {
				var_dump($e);
				die("An error occurred when attempting a coin RPC call.\n");
			}
		}

		$runtime_sec = microtime(true)-$script_start_time;
		$sec_until_refresh = round($script_target_time-$runtime_sec);
		if ($sec_until_refresh < 0) $sec_until_refresh = 0;

		if ($print_debug) echo "</pre>";
		if (empty($argv)) echo '<script type="text/javascript">setTimeout("window.location=window.location;", '.(1000*$sec_until_refresh).');</script>'."\n";
		if ($print_debug) echo "Script ran for ".round($runtime_sec, 2)." seconds.\n";
		
		if ($GLOBALS['process_lock_method'] == "db") $app->set_site_constant($GLOBALS['shutdown_lock_name'], 0);
	}
	else echo "Skipped starting the game loop; it's already running (started ".$app->format_seconds(time()-$app->get_site_constant("last_script_run_time"))." ago).\n";
	if ($print_debug) echo "</pre>";
}
else echo "Error: incorrect key supplied in cron/minutely_main.php\n";
?>
