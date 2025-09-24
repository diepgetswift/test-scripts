<?php 
require "../auth.php";

$sourceRouteId = 18;
$destinationRoute = 15;

$usernames = [
"christopheraguim@gmail.com",
"srinu43@gmail.com",
"allenkaty93@gmail.com",
"billsranda@gmail.com",
"anderisa000@gmail.com",
"paulina.armstrong@gmail.com",
"keifbailey@gmail.com",
"briannalburrus@gmail.com",
"mecardamone@outlook.com",
"imthelchew@gmail.com",
"Lisamarieclark816@gmail.com",
"stephcook512@gmail.com",
"ccrowley0729@gmail.com",
"vi.dashtara@gmail.com",
"jenn@thedeanslistgroup.com",
"feevola@yahoo.com",
"mchlnrqz@gmail.com",
"afiascone@gmail.com",
"laurieleefinch@gmail.com",
"fleming.suzanne@gmail.com",
"michaelforte@me.com",
"hcenchantedmushrooms@gmail.com",
"dorothyditomaso@yahoo.com",
"lynanne.guggenheim@gmail.com",
"brianalee6@gmail.com",
"jhtexanwelds@gmail.com",
"glecalvez@gmail.com",
"cahuston1@gmail.com",
"diane@kahanek.net",
"michaellavian1@gmail.com",
"vllurie@gmail.com",
"Shayelove314@yahoo.com",
"nicole.manley56@gmail.com",
"matthewbentonmay@gmail.com",
"nicoleelittle@gmail.com",
"heal4real.amm@gmail.com",
"dylan.monahan@outlook.com",
"lindseymarie96@gmail.com",
"gregracino@gmail.com",
"jordieballs@gmail.com",
"kdhr888@gmail.com",
"katie.sanden@gmail.com",
"casielulu@yahoo.com",
"tom.shaddix@gmail.com",
"janashows@protonmail.com",
"clairesorrels@gmail.com",
"toekneetino21@gmail.com",
"taylor.zachbarnett@gmail.com",
"lynetteag128@gmail.com",
"walkerhouse1@prodigy.net",
"bethwheeler59@gmail.com",
"stevewilcox2001@yahoo.com",
"cwventures@pm.me",
"sfwiii.tw@gmail.com",
"cristina.brianwood@gmail.com",
"amber@frontyardbrewing.com"
];

foreach ($usernames as $username) {
    $check1 = func_query_first_cell("SELECT * FROM dbp_users_routes where login = '{$username}' AND route_id = '{$sourceRouteId}'");
    $check2 = func_query_first_cell("SELECT * FROM dbp_users_routes where login = '{$username}' AND route_id = '{$destinationRoute}'");
    $check3 = func_query_first_cell("SELECT * FROM dbp_orders where login = '{$username}' AND route_id = '{$destinationRoute}' AND date > unix_timestamp()");
    
    if (!$check1 || !$check2 || $check3) {
        echo "Validateion fail" . $username . PHP_EOL;
        continue;
    }
    
    $userinfo = new UserInfo($username);
    $userinfo->move_user_orders($sourceRouteId, $destinationRoute, true, false);

    // Copy recurring order
    //$userinfo->copy_recurring_order($sourceRouteId, $destinationRoute, true);
    db_query("update dbp_recurring_orders set route_id = {$destinationRoute} where login = '{$username}' AND route_id = {$sourceRouteId}");
    
    $userinfo->unassign_from_route($sourceRouteId);
}
