<?php
require(AppSettings::srcPath()."/includes/connect.php");
require(AppSettings::srcPath()."/includes/get_session.php");

if ($thisuser && $game && $app->synchronizer_ok($thisuser, $_REQUEST['synchronizer_token'])) {
	$user_game = $thisuser->ensure_user_in_game($game, false);
	
	if ($user_game) {
		if (!empty($_REQUEST['change_to_currency_id'])) {
			$change_to_currency_id = (int) $_REQUEST['change_to_currency_id'];
			$user_game = $thisuser->set_buyin_currency($user_game, $change_to_currency_id);
		}
		
		$user_enters_game_amount = true;
		
		$coins_in_existence = ($game->coins_in_existence(false, true)+$game->pending_bets(true))/pow(10, $game->db_game['decimal_places']);
		
		if ($game->db_game['buyin_policy'] == "for_sale") {
			$buyin_currency = $app->fetch_currency_by_id($user_game['buyin_currency_id']);
			$escrow_value = $game->escrow_value_in_currency($user_game['buyin_currency_id'], $coins_in_existence);
			$ref_user = false;
			$pay_to_account = $game->check_set_blockchain_sale_account($ref_user, $buyin_currency);
			$game_sale_account = $game->check_set_game_sale_account($ref_user);
			$game_sale_amount = $game->account_balance($game_sale_account['account_id']);
		}
		else {
			$buyin_currency = $app->fetch_currency_by_id($game->blockchain->currency_id());
			$escrow_address = $game->blockchain->create_or_fetch_address($game->db_game['escrow_address'], false, null);
			$escrow_value = $game->escrow_value(false)/pow(10, $game->db_game['decimal_places']);
			$pay_to_account = $thisuser->fetch_currency_account($buyin_currency['currency_id']);
		}
		
		$buyin_blockchain = new Blockchain($app, $buyin_currency['blockchain_id']);
		
		if ($escrow_value > 0) {
			$exchange_rate = $coins_in_existence/$escrow_value;
		}
		else $exchange_rate = 0;
		
		$output_obj = [];
		$content_html = "";
		
		if (!empty($_REQUEST['action']) && $_REQUEST['action'] == "check_amount") {
			if (!empty($_REQUEST['invoice_id'])) {
				$invoice_id = (int) $_REQUEST['invoice_id'];
				
				$invoice = $app->fetch_invoice_by_id($invoice_id);
				
				if (isset($invoice) && $invoice['pay_currency_id'] != $buyin_currency['currency_id']) $invoice = null;
			}
			
			if (empty($invoice)) {
				if ($pay_to_account) {
					$invoice_type = "buyin";
					if ($game->db_game['buyin_policy'] == "for_sale") $invoice_type = "sale_buyin";
					
					$invoice = $app->new_currency_invoice($pay_to_account, $pay_to_account['currency_id'], false, $thisuser, $user_game, $invoice_type);
					$invoice_id = $invoice['invoice_id'];
				}
				else $content_html .= "Failed to generate a deposit address.";
			}
			
			if ($user_enters_game_amount) {
				$color_amount = 0;
				
				$receive_amount = floatval(str_replace(",", "", urldecode($_REQUEST['buyin_amount'])));
				$pay_amount = $receive_amount/$exchange_rate;
				$buyin_amount = $pay_amount;
			}
			else {
				$buyin_amount = floatval(str_replace(",", "", urldecode($_REQUEST['buyin_amount'])));
				$color_amount = floatval(str_replace(",", "", urldecode($_REQUEST['color_amount'])));
				
				$pay_amount = $buyin_amount+$color_amount;
				$receive_amount = $buyin_amount*$exchange_rate;
			}
			
			$invoice = $app->fetch_invoice_by_id($invoice_id);
			
			if ($invoice) {
				$invoice_address = $app->fetch_address_by_id($invoice['address_id']);
				
				$app->run_query("UPDATE currency_invoices SET buyin_amount=:buyin_amount, color_amount=:color_amount, pay_amount=:pay_amount WHERE invoice_id=:invoice_id;", [
					'buyin_amount' => $buyin_amount,
					'color_amount' => $color_amount,
					'pay_amount' => $pay_amount,
					'invoice_id' => $invoice['invoice_id']
				]);
				
				$buyin_amount_ok = true;
				
				if ($game->db_game['buyin_policy'] == "for_sale") {
					$max_buyin_amount = $game_sale_amount/pow(10, $game->db_game['decimal_places'])/$exchange_rate;
					if ($buyin_amount > $max_buyin_amount) {
						$buyin_amount_ok = false;
						$content_html .= '<p class="text-danger">Don\'t send that many '.$buyin_blockchain->db_blockchain['coin_name_plural'].'. There are only '.$game->display_coins($game_sale_amount).' available ('.$app->format_bignum($max_buyin_amount)." ".$buyin_currency['abbreviation'].")</p>\n";
					}
				}
				
				if ($game->db_game['min_buyin_amount'] && $receive_amount < $game->db_game['min_buyin_amount']) {
					$content_html .= '<p class="text-danger">Please deposit at least '.$game->db_game['min_buyin_amount']." ".($game->db_game['min_buyin_amount']=="1" ? $game->db_game['coin_name'] : $game->db_game['coin_name_plural']).".</p>\n";
					$buyin_amount_ok = false;
				}
				
				if ($buyin_amount_ok) {
					$content_html .= '<p>';
					if ($user_enters_game_amount) {
						$content_html .= 'To get '.$receive_amount.' '.($receive_amount=="1" ? $game->db_game['coin_name'] : $game->db_game['coin_name_plural']).', please deposit '.$app->to_significant_digits($pay_amount, 8).' '.($app->to_significant_digits($pay_amount, 8)=="1" ? $buyin_currency['short_name'] : $buyin_currency['short_name_plural']).'. ';
					}
					else {
						$content_html .= 'For '.$buyin_amount.' '.($buyin_amount=="1" ? $buyin_currency['short_name'] : $buyin_currency['short_name_plural']).', you\'ll receive approximately '.$app->format_bignum($receive_amount).' '.($app->format_bignum($receive_amount)=="1" ? $game->db_game['coin_name_plural'] : $game->db_game['coin_name_plural']).'. ';
					}
					$content_html .= 'Send '.$buyin_currency['short_name_plural'].' to '.$invoice_address['address'].'
					</p>
					<p>
						<center><img style="margin: 10px;" src="/render_qr_code.php?data='.$invoice_address['address'].'" /></center>
					</p>
					<p>
						'.ucfirst($game->db_game['coin_name_plural']).' will automatically be credited to this account when your payment is received.
					</p>';
				}
				
				$output_obj['invoice_id'] = $invoice['invoice_id'];
			}
			else $content_html .= "There was an error loading this invoice.";
		}
		else if (!empty($_REQUEST['action']) && $_REQUEST['action'] == "refresh") {}
		else {
			$content_html = '<p>You can get '.$game->db_game['coin_name_plural'].' ('.$game->db_game['coin_abbreviation'].') by depositing another currency like Bitcoin or Litecoin here. If you already have '.$game->db_game['coin_name_plural'].' elsewhere and want to send them to this wallet, go to <a href="/wallet/'.$game->db_game['url_identifier'].'/?initial_tab=4">Send & Receive</a> instead.</p>'."\n";
			
			$content_html .= '<p>If you find that there are no '.$game->db_game['coin_name_plural'].' available here, you may need to use an external exchange to buy '.$game->db_game['coin_abbreviation'].".</p>\n";
			
			$content_html .= '<div class="form-group"><label for="buyin_currency_id">What do you want to deposit?</label>';
			$content_html .= '<select class="form-control" id="buyin_currency_id" name="buyin_currency_id" onchange="thisPageManager.change_buyin_currency(this);">';
			$content_html .= "<option value=\"\">-- Please Select --</option>\n";
			$buyin_currencies = $app->run_query("SELECT * FROM currencies c JOIN blockchains b ON c.blockchain_id=b.blockchain_id WHERE b.p2p_mode='rpc' ORDER BY c.name ASC;");
			while ($a_buyin_currency = $buyin_currencies->fetch()) {
				$content_html .= "<option ";
				if ($a_buyin_currency['currency_id'] == $buyin_currency['currency_id']) $content_html .= "selected=\"selected\" ";
				$content_html .= "value=\"".$a_buyin_currency['currency_id']."\">".$a_buyin_currency['name']."</option>\n";
			}
			$content_html .= "</select>\n";
			$content_html .= "</div>\n";
			
			$content_html .= "<p>The exchange rate is ".$app->format_bignum($exchange_rate)." ".$game->db_game['coin_name_plural']." per ".$buyin_currency['short_name'].".</p>\n";
			
			$content_html .= '<p>';
			$buyin_limit = 0;
			if ($game->db_game['buyin_policy'] == "none") {
				$content_html .= "Sorry, buy-ins are not allowed in this game.";
			}
			else if ($game->db_game['buyin_policy'] == "unlimited") {
				$content_html .= "You can buy in for as many coins as you want in this game. ";
			}
			else if ($game->db_game['buyin_policy'] == "game_cap") {
				$content_html .= "This game has a game-wide buy-in cap of ".$app->format_bignum($game->db_game['game_buyin_cap'])." ".$game->blockchain->db_blockchain['coin_name_plural'].". ";
			}
			else if ($game->db_game['buyin_policy'] == "for_sale") {
				$content_html .= "There are ".$game->display_coins($game_sale_amount)." for sale. ";
			}
			else $content_html .= "Invalid buy-in policy.";
			
			$content_html .= "</p>\n";
			
			if ($game->db_game['buyin_policy'] == "for_sale") {
				if ($buyin_blockchain->db_blockchain['online'] == 1) {
					if ($user_enters_game_amount) {
						$content_html .= '
						<div class="form-group">
							<label for="buyin_amount">How many '.$game->db_game['coin_name_plural'].' do you want to receive?'.($game->db_game['min_buyin_amount'] ? ' &nbsp; (Minimum: '.$game->db_game['min_buyin_amount'].')' : '').'</label>
							<input type="text" class="form-control" id="buyin_amount" />
						</div>';
					}
					else {
						$content_html .= '
						<p>
							How many '.$buyin_currency['short_name_plural'].' do you want to deposit?
						</p>
						<p>
							<div class="row">
								<div class="col-sm-12">
									<input type="text" class="form-control" id="buyin_amount">
								</div>
							</div>
						</p>';
					}
					
					$content_html .= '<button class="btn btn-primary" onclick="thisPageManager.manage_buyin(\'check_amount\');">Check</button>'."\n";
				}
				else {
					$content_html .= '<p class="text-danger">You can\'t buy '.$game->db_game['coin_name_plural'].' with '.$buyin_currency['abbreviation'].' here right now. '.$buyin_blockchain->db_blockchain['blockchain_name']." is not running on this node.</p>\n";
				}
			}
			else {
				$content_html .= '
				<p>
					How many '.$game->blockchain->db_blockchain['coin_name_plural'].' do you want to spend?
				</p>
				<p>
					<div class="row">
						<div class="col-sm-12">
							<input type="text" class="form-control" id="buyin_amount">
						</div>
					</div>
				</p>
				<p>
					How many '.$game->blockchain->db_blockchain['coin_name_plural'].' do you want to color?
				</p>
				<p>
					<div class="row">
						<div class="col-sm-12">
							<input type="text" class="form-control" id="color_amount">
						</div>
					</div>
				</p>';
				
				$content_html .= '<button class="btn btn-primary" onclick="thisPageManager.manage_buyin(\'check_amount\');">Check</button>'."\n";
			}
		}
		
		list($num_buyin_invoices, $buyin_invoices_html) = $game->display_buyins_by_user_game($user_game['user_game_id'], $buyin_blockchain);
		
		$invoices_html = '<p style="margin-top: 10px;">Please wait for your '.$buyin_blockchain->db_blockchain['blockchain_name'].' payment to be confirmed before '.$game->db_game['coin_name_plural'].' will be deposited to your account.</p>'."\n";
		
		if ($num_buyin_invoices > 0) {
			$invoices_html .= '<p>You have '.$num_buyin_invoices.' buyin address';
			if ($num_buyin_invoices != 1) $invoices_html .= 'es';
			$invoices_html .= '. ';
		}
		
		$invoices_html .= '<div class="buyin_sellout_list">'.$buyin_invoices_html."</div></p>\n";
		
		$output_obj['content_html'] = $content_html;
		$output_obj['invoices_html'] = $invoices_html;
		$output_obj['invoices_hash'] = AppSettings::standardHash($invoices_html);
		
		$app->output_message(1, "", $output_obj);
	}
	else $app->output_message(3, "You're not logged in to this game.", false);;
}
else $app->output_message(2, "Error: it looks like you're not logged into this game.", false);
?>
