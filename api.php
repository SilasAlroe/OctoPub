<?php
//Set up redis
$r = new Redis();
$r->connect('127.0.0.1');
$r->select(0);

//set up thread life vars
$baseTimeout = 172800; //The default lifespan of a thread - Two days
$minTimeout = 3600; //Minimum lifespan of a thread - An hour
$maxTimeout = 1209600; //Maximum timeout of a thread - Two weeks
$modTime = 600; //Time added per post and subtracted per thread. - 10 min

//Check what kind of request is received and handle it appropriately
if (isset($_REQUEST["fromId"])) {
    echo json_encode(getFrom($_REQUEST["thread"], $_REQUEST["fromId"]));
} elseif (isset($_REQUEST["getHistoryFrom"])) {
    echo json_encode(getHistory($_REQUEST["getHistoryFrom"]));
} elseif (isset($_REQUEST["addMessage"])) {
    if (authenticate($_REQUEST["userId"], $_REQUEST["secId"])) {
        newMsg(htmlspecialchars($_REQUEST["thread"], ENT_QUOTES), substr(htmlspecialchars($_REQUEST["addMessage"], ENT_QUOTES), 0, 1000), htmlspecialchars($_REQUEST["userId"], ENT_QUOTES));
        updateThread(htmlspecialchars($_REQUEST["thread"], ENT_QUOTES));
    } else {
        echo "ERR: OLDID";
    }
} elseif (isset($_REQUEST["addThread"])) {
    if (hasPostedLately()) {
        echo "ERR: TOOSOON";
    } else {
        if ($_REQUEST["addThread"] != "") {
            $newId = generateID();
            newThread($newId, substr(htmlspecialchars($_REQUEST["addThread"], ENT_QUOTES), 0, 200), substr(htmlspecialchars($_REQUEST["text"], ENT_QUOTES), 0, 1000));
            echo json_encode(returnID($newId));
        }
    }
    saveUserHash();
} elseif (isset($_REQUEST["getThreads"])) {
    echo json_encode(getThreads());
} elseif (isset($_REQUEST["getThread"])) {
    echo json_encode(getThread($_REQUEST["getThread"]));
} elseif (isset($_REQUEST["newID"])) {
    echo json_encode(returnID(generateID()));
} else {
    echo showInfo();
}


function getFrom($prefix, $id)
{
    //Gets all messages from a thread since id.
    global $r;
    $messages = array();
    $latestMsg = $r->get("t_" . $prefix);
    $messagesToGet = array();
    for ($i = $id + 1; $i <= $latestMsg; $i++) {
        $messagesToGet[$i - $id] = $prefix . "_" . $i;
    }
    $jmessages = $r->mGet($messagesToGet);
    if ($jmessages[0] != "") {
        foreach ($jmessages as $jmessage) {
            $message = json_decode($jmessage);
            if ($message != null) {
                $messages[] = $message;
            }
        }
    }
    return $messages;
}

function getHistory($prefix)
{
    //Gets latest 20 messages from a thread.
    global $r;
    $messages = array();
    $latestMsg = $r->get("t_" . $prefix);
    $messagesToGet = array();
    for ($i = $latestMsg - 20; $i <= $latestMsg; $i++) {
        $messagesToGet[$i - $latestMsg + 20] = $prefix . "_" . $i;
    }
    $jmessages = $r->mGet($messagesToGet);
    foreach ($jmessages as $jmessage) {
        $message = json_decode($jmessage);
        if ($message != null) {
            $messages[] = $message;
        }
    }
    return $messages;
}

function newMsg($prefix, $msg, $userId)
{
    //Make sure message contains something.
    if (isNothing($msg)) {
        echo "ERR: Needs more than whitespace";
        return;
    }

    global $r;

    //Anti spam thingy
    $userHash = "message_".hash("md4", $_SERVER['REMOTE_ADDR']);
    $r->select(2);
    if ($r->exists($userHash)) {
        $messageCount = $r->get($userHash);
        if ($messageCount > 5) {
            echo"ERR: Too many messages in short time, please wait.";
            if ($messageCount != 999){
                $r->set($userHash, 999);
                $r->expire($userHash, 30);
            }
            return;
        }else{
            $messageCount += 1;
            $r->set($userHash, $messageCount);
            $r->expire($userHash, 4);
        }
    } else {
        $r->set($userHash, 1);
        $r->expire($userHash, 4);
    }

    //Create new message on a thread using a message text and user id.
    $r->select(0);
    if ($msg != $r->get("latestMsg")) {
        $msgId = $r->incr("t_" . $prefix);
        $msgArray = json_encode(array($msg, $userId, time(), $msgId));
        $r->set($prefix . "_" . $msgId, $msgArray);
        $r->setTimeout($prefix . "_" . $msgId, 604800);
    }
    $r->set("latestMsg", $msg);
}

function newThread($prefix, $title, $text)
{
    //Make sure thread title contains something.
    if (isNothing($title)) {
        echo "ERR: Needs more than whitespace";
        return;
    }
    //Create a new thread from a previously generated id and text.
    global $r;
    $r->select(0);
    $r->set("t_" . $prefix, -1);
    $r->set("title_" . $prefix, $title);
    $r->set("text_" . $prefix, $text);
    updateThread($prefix);
    //$msgArray = json_encode(array($title, $prefix, time(), 0));
    //$r->set($prefix . "_0", $msgArray);
    //$r->setTimeout($prefix . "_0", $timeOut);
}

function updateThread($prefix)
{
    //Reset the timeout for a thread.
    global $r, $baseTimeout, $minTimeout, $maxTimeout, $modTime;
    $replyCount = count($r->keys($prefix . "*"));
    $threadCount = count($r->keys("t_*"));
    $timeout = minMax($baseTimeout + $replyCount * $modTime - $threadCount * $modTime, $minTimeout, $maxTimeout);
    $r->setTimeout("t_" . $prefix, $timeout);
    $r->setTimeout("title_" . $prefix, $timeout);
    $r->setTimeout("text_" . $prefix, $timeout);
    //$r->setTimeout($prefix . "_0", $timeout);
}

function getThreads()
{
    //Get all threads.
    global $r;
    $threadNames = $r->keys('title_*');
    sort($threadNames);
    $threadsToGet = array();
    $threadIds = array();
    $threadLengthsToGet = array();
    foreach ($threadNames as $threadName) {
        $threadsToGet[] = $threadName;
        $threadIds[] = substr($threadName, 6);
        $threadLengthsToGet[] = "t_" . substr($threadName, 6);
    }
    $titles = $r->mGet($threadsToGet);
    $threadLengths = $r->mget($threadLengthsToGet);
    $threads = array();
    $i = 0;
    foreach ($threadsToGet as $thread) {
        $threads[] = array($titles[$i], $threadIds[$i], $threadLengths[$i]);
        $i++;
    }
    //Return structure: array of arrays with title and id
    return $threads;
}

function getThread($id)
{
    //Get title and text of a thread
    global $r;
    $title = $r->get("title_" . $id);
    $text = $r->get("text_" . $id);
    return array($id, $title, $text);
}

function generateID()
{
    return strtoupper(str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT));
}

function returnID($id)
{
    //Hash and return id and secId.
    global $r;
    //Limit the user to 50 id requests in a 5min interval.
    $userHash = "id_".hash("md4", $_SERVER['REMOTE_ADDR']);
    $r->select(2);
    if ($r->exists($userHash)) {
        $idRequests = $r->get($userHash);
        $idRequests += 1;
        $r->set($userHash, $idRequests);
        $r->expire($userHash, 300);
        if ($idRequests > 50) {
            return "ERR: Too many requests for a new id.";
        }
    } else {
        $r->set($userHash, 1);
        $r->expire($userHash, 300);
    }
    $r->select(1);
    //Get salty
    $salt = $r->get("salt");
    //Generate id hash
    $secId = hash("sha256", $id . $salt);
    //Return array
    return array($id, $secId);
}

function authenticate($id, $secId)
{
    global $r;
    $r->select(1);
    //Get salty
    $salt = $r->get("salt");
    $calcSecId = hash("sha256", $id . $salt);
    if ($secId == $calcSecId) {
        return true;
    } else {
        return false;
    }
}

function hasPostedLately()
{
    global $r;
    $r->select(2);
    $userHash = hash("md4", $_SERVER['REMOTE_ADDR']);
    if ($r->exists($userHash)) {
        return true;
    }
    return false;
}

function saveUserHash()
{
    global $r;
    $r->select(2);
    $userHash = hash("md4", $_SERVER['REMOTE_ADDR']);
    $r->set($userHash, true);
    $r->expire($userHash, 600);
}

function isNothing($text)
{
    $clearText = preg_replace("/\s/","", $text);
    if ($clearText == "") {
        return true;
    };
    return false;
}

function minMax($value, $min, $max)
{
    //keep value between min and max
    if ($value < $min) {
        $value = $min;
    } elseif ($value > $max) {
        $value = $max;
    }
    return $value;
}

function showInfo()
{
    //Return some info and stats about the api/db
    global $r;
    $ip = $_SERVER['REMOTE_ADDR'];
    $returnText = "You have just come upon the octopub api page! <br>" .
        "You are completely welcome to use this for whatever, just dont abuse it please.<br>" .
        "Here's at least some of the available requests atm: <br>" .
        "<code>Get messages in thread from id: <br>/api.php?thread='threadId'&fromId='messageNumber' <br><br>" .
        "Get message history of a thread (latest 20 nessages): <br>/api.php?getHistoryFrom='threadId' <br><br>" .
        "Add a new message to a thread: <br>/api.php?thread='threadId'&addMessage='message'&UserId='idOfSubmitter'&secId='hashIdOfSubmitter' <br>" .
        "//Please note that there is an enforced character limit of 1000. <br>" .
        "//You should use post instead of get, as get only supports ascii characters and no newlines <br><br>" .
        "Add a new thread: <br>/api.php?addThread='threadTitle'&text='threadText' <br>" .
        "//Please note again that title is limited to 200 chars and text to 1000<br>" .
        "//Also once again, you should really use post for this...<br>" .
        "//Actually... You should really use post for everything here...<br><br>" .
        "Get all available threads: <br>/api.php?getThreads='whatever' <br><br>" .
        "Get more info for a specific thread: <br>/api.php?getThread='threadId' <br><br>" .
        "Get a user id: <br> /api.php?newID<br> //gets an array of two items: An id, and a hashed version of the id used for authentication.<br>" .
        "</code>You should really take a look at the source code to get a better idea of how this all works. <br>" .
        "Source can be found at <a href='https://github.com/SarahAlroe/OctoPub'>https://github.com/SarahAlroe/OctoPub</a><br>" .
        "If you have any further questions, do feel free to contact me :)<br>" .
        "--><a href='http://sarah.alroe.dk/'>Sarah Fjelsted Alroe</a><br><br>" .
        "And now to something a bit more entertaining (Still quite lame if you didn't find any of the othe stuff interesting): <br>" .
        "Number of keys in database 0: <code>" . $r->dbSize() . "</code><br>" .
        "Random key from the database: <code>" . $r->get($r->randomKey()) . "</code><br>";
    $r->select(1);
    $returnText .= "Number of images currently stored on the server: <code>" . $r->dbSize() . "</code><br>" .
        "Random image: <img src='https://octopub.cf/img/" . $r->randomKey() . "'><br>" .
        "Quite cool innit?";

    return $returnText;
}

?>
