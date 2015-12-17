<?php

require "./data-layer/dbCommunication.php";

// Replacement Function for PHP < 5.6.0 (Required for hash comparisons)
if(!function_exists('hash_equals')){
    function hash_equals($str1, $str2){
        if(strlen($str1) != strlen($str2)){
            return false;
        }else{
            $res = $str1 ^ $str2;
            $ret = 0;
            for($i = strlen($res) - 1; $i >= 0; $i--){
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
}

function CreateUser($Username, $Password){

    // Hash and salt password
    $cost = 10;
    $salt = strtr(base64_encode(mcrypt_create_iv(16, MCRYPT_DEV_URANDOM)), '+', '.');
    $salt = sprintf("$2a$%02d$", $cost) . $salt;
    $HashSaltedPassword = crypt($Password, $salt);

    $return_status = DBCreateUser($Username, $HashSaltedPassword);


    if($return_status === SUCCESS){
        // Add to Leaderboard with no stats
        DBAddUserToLeaderboard($Username);
        return array(SUCCESS, "Successfully created user."); 
    }elseif($return_status === USERNAME_ALREADY_EXISTS){
        return array(FAILURE, "Username already exists."); 
    }else{
        return array(FAILURE, "Failed created user."); 
    }
}

function UserLogin($Username, $Password){
    $return_status = DBUserLogin($Username, $Password);

    if($return_status === SUCCESS){
        // Destroy any existing token
        DBDestroyToken($Username);

        // Create token
        $NewToken = bin2hex(openssl_random_pseudo_bytes(64));

        // Store in SESSION
        $_SESSION["Token"] = $NewToken;
        $_SESSION["Username"] = $Username;

        // Store in DB
        DBSetToken($Username, $NewToken);

        return array(SUCCESS, "Successfully logged user in."); 
    }elseif($return_status === LOGIN_FAILED){
        return array(FAILURE, "Login failed."); 
    }else{
        return array(FAILURE, "Error."); 
    }
}

function UserLogout($Username){
    // Destory token
    DBDestroyToken($Username);
    
    // Destroy session
    $_SESSION["Token"] = NULL;

    // Execute logout procedure
}

function IsAuthorized(){
    // Eliminates warning and prevents potential security bug
    $Username = isset($_SESSION["Username"]) ? $_SESSION["Username"] : NULL;
    $SessionToken = isset($_SESSION["Token"]) ? $_SESSION["Token"] : NULL;
    $return = DBGetToken($Username);

    if($return !== FAILURE){
        if($SessionToken === $return)
            return true;
        else
            return false;
    }else{
        return false;
    }
}

function ShuffleDeck($GameID){
    $OrderedDeckString = "1S2S3S4S5S6S7S8S9STSJSQSKS1H2H3H4H5H6H7H8H9HTHJHQHKH1D2D3D4D5D6D7D8D9DTDJDQDKD1C2C3C4C5C6C7C8C9CTCJCQCKC";
    $OrderedDeckArr = str_split($OrderedDeckString, 2);
    shuffle($OrderedDeckArr);
    $ShuffledDeckString = implode($OrderedDeckArr);

    DBUpdateGameDeck($GameID, $ShuffledDeckString);
}

function CreateGame($GameName, $Username){
    // Creates the game
    DBCreateGame($GameName);

    // Gets the GameID (Last inserted ID)
    $GameID = DBGetLastInsertID();

    // Store GameID in SESSION
    $_SESSION["GameID"] = $GameID;

    // Shuffles the deck in the game
    ShuffleDeck($GameID);

    // Adds user to the game
    DBAddUserToGame($GameID, $Username);

    // User is added, now update user to ACTIVE (First user, goes first)
    DBUpdateUserGameStatus($GameID, $Username, "ACTIVE");

    // DEAL OUT
    // Get number of players + dealer
    $GameUsers = DBGetGameUsers($GameID, "ACTIVE");
    $DealerHand = DBGetDealerHand($GameID);
    $Deck = DBGetGameDeck($GameID);
    $DeckPointer = $Deck[0]->DeckPointer;

    // Loop twice (two cards deal out)
    for($i = 0; $i < 2; $i++){
        // Deal out to dealer
        $ZeroPadCard = sprintf("%02d", $DeckPointer);
        $DealerHand .= $ZeroPadCard;
        $DeckPointer++;

        foreach($GameUsers as $User){
            $ZeroPadCard = sprintf("%02d", $DeckPointer);
            $User->UserHand .= $ZeroPadCard;
            $DeckPointer++;
        }
    }

    // Update DB with UserHands and DealerHand
    foreach($GameUsers as $User){
        $Username = $User->Username;
        $UserHand = $User->UserHand;
        DBUpdateUserHand($GameID, $Username, $UserHand);
    }

    DBUpdateDealerHand($GameID, $DealerHand);
    
    // Update DeckPointer
    DBUpdateDeckPointer($GameID, $DeckPointer);

    // Update UserTurn in Games to Username
    $Username = $_SESSION["Username"];
    DBUpdateUserTurn($Username, $GameID);

    // Update User Board with the appropriate card mappings
    $UserTurn = DBGetUserTurn($GameID);
    $_SESSION["UserTurn"] = $UserTurn;
    //UpdateBoard();
}

function UpdateBoard(){
    // Get all user hands
    $GameID = $_SESSION["GameID"];
    $CurrentDeckString = DBGetGameDeck($GameID)[0]->CurrentDeck;
    $CurrentDeckArr = str_split($CurrentDeckString, 2);
    $GameUsers = DBGetGameUsers($GameID, "ACTIVE");

    foreach($GameUsers as $User){
        $CurrentUsername = $User->Username;
        $CardIndexStr = $User->UserHand;
        $CardIndexArr = str_split($CardIndexStr, 2);

        echo $CurrentUsername . "'s Hand: ";
        foreach($CardIndexArr as $CardIndex){
            // convert to int
            $UnpaddedCardIndex = intval($CardIndex);
            echo $CurrentDeckArr[$UnpaddedCardIndex] . " ";
        }

        echo "\n";
    }

    // Dealer Hand
    $DealerCardIndexStr = DBGetDealerHand($GameID);
    $DealerCardIndexArr = str_split($DealerCardIndexStr, 2);

    echo "Dealer's Hand: ";
    foreach($DealerCardIndexArr as $CardIndex){
        // convert to int
        $UnpaddedCardIndex = intval($CardIndex);
        echo $CurrentDeckArr[$UnpaddedCardIndex] . " ";
    }
    
    echo "\n";

    // User resumes 2s check in
}

function AddUserToGame($GameID, $Username){
    DBAddUserToGame($GameID, $Username);   
    // USER IS WAITING
}

function RemoveUserFromGame(){
    // Need to check if active user and fold and further move to next user if so
}

function NewHand($GameID){
    $WaitingGameUsers = DBGetGameUsers($GameID, "WAITING");
    $LeftGameUsers = DBGetGameUsers($GameID, "LEFT");

    foreach($WaitingGameUsers as $User){
        $Username = $User->Username;
        DBUpdateUserGameStatus($GameID, $Username, "ACTIVE");
    }

    foreach($LeftGameUsers as $User){
        $Username = $User->Username;
        //DBRemoveUserFromGame();
    }
    // Set waiting users to active
    // Remove Left Users
    
    // Get GameUsers, see if any are WAITING or  LEFT, set to ACTIVE or remove item in GameUsers
    // Shuffle Deck 
}

function IsUserTurn($Username, $GameID){
    // Compare $_SESSION["UserTurn"] with DB UserTurn (Query every two seconds)
    $GameID = $_SESSION["GameID"];
    $LastUserTurn = $_SESSION["UserTurn"];
    $CurrentUserTurn = DBGetUserTurn($GameID);

    if($LastUserTurn !== $CurrentUserTurn){
        // New user, update board
        $_SESSION["UserTurn"] = $CurrentUserTurn;
        //UpdateBoard();
    }

    if($_SESSION["UserTurn"] === $_SESSION["Username"]){
        // It is our turn, update controls to allow and give ability to fold / stay / hit
        return true;
    }else{
        return false;
    }
}

function HitPlayer($Username){
    // Hit player with next card.

    $GameID = $_SESSION["GameID"];
    $UserHand = DBGetUserHand($GameID, $Username);
    $Deck = DBGetGameDeck($GameID);
    $DeckPointer = $Deck[0]->DeckPointer;

    $ZeroPadCard = sprintf("%02d", $DeckPointer);
    $UserHand .= $ZeroPadCard;
    $DeckPointer++;

    // Update the UserHand and DeckPointer
    DBUpdateUserHand($GameID, $Username, $UserHand);
    DBUpdateDeckPointer($GameID, $DeckPointer);
    
    CheckIfBust();
    // Update DB with new card
    // Determine of bust
    //   If so IncremenetHandsLost(UserID)
}

function FoldPlayer($Username){
    // Update UserTurn
    UpdateUserTurn();
}

function StayPlayer(){
    // Update UserTurn
    UpdateUserTurn();
}

// Refactor some of this code to a generic value detector
function CheckIfBust(){
    $Username = $_SESSION["Username"];
    $GameID= $_SESSION["GameID"];
    $CurrentDeckString = DBGetGameDeck($GameID)[0]->CurrentDeck;
    $CardIndexStr = DBGetUserHand($GameID, $Username);
    $CurrentDeckArr = str_split($CurrentDeckString, 2);
    $CardIndexArr = str_split($CardIndexStr, 2);

    // Cumulative point value of the cards
    $Cumulative = 0;

    // How many aces do we have? (Detriment as we fake bust)
    $NumAce = 0;

    $HandInformation = DetermineHandValue($CardIndexArr, $CurrentDeckArr);
    $Cumulative = $HandInformation[0];
    $NumAce = $HandInformation[1];

    // Determine Bust
    if($Cumulative > 21){
        for($NumAce; $NumAce > 0; $NumAce--){
            $Cumulative -= 10;
            if($Cumulative <= 21){
                break;
            }
        }
    }

    if($Cumulative > 21){
        // If bust, Update UserTurn, update HandsLost
        DBIncrementHandsLost($Username);
        UpdateUserTurn();
        return true;
    }

    return false;
}

function DetermineHandValue($CardIndexArr, $CurrentDeckArr){
    // Cumulative point value of the cards
    $Cumulative = 0;

    // How many aces do we have? (Detriment as we fake bust)
    $NumAce = 0;

    foreach($CardIndexArr as $CardIndex){
        // convert to int
        $UnpaddedCardIndex = intval($CardIndex);
        $CurrentCard = $CurrentDeckArr[$UnpaddedCardIndex];
        $CardVal = $CurrentCard[0];

        switch($CardVal){
            case '1':
                $Cumulative += 11;
                $NumAce++;
                break;
            case 'K':
            case 'Q':
            case 'J':
            case 'T':
                $Cumulative += 10;
                break;
            default:
                $Cumulative += intval($CardVal);
        }
    }

    return array($Cumulative, $NumAce);
}

function DealerHit(){
    
}

function DealerPlay(){
    // Dealer play rules
    // Hit if < 17, stay on 17+

    $GameID= $_SESSION["GameID"];

    while(true){
        $Deck = DBGetGameDeck($GameID)[0];
        $DeckPointer = $Deck->DeckPointer;
        $CurrentDeckString = $Deck->CurrentDeck;

        $CardIndexStr = DBGetDealerHand($GameID);
        $CurrentDeckArr = str_split($CurrentDeckString, 2);
        $CardIndexArr = str_split($CardIndexStr, 2);

        $HandInformation = DetermineHandValue($CardIndexArr, $CurrentDeckArr);
        $Cumulative = $HandInformation[0];
        $NumAce = $HandInformation[1];

        // Deduct any Aces if we exceed 21
        if($Cumulative > 21){
            for($NumAce; $NumAce > 0; $NumAce--){
                $Cumulative -= 10;
                if($Cumulative <= 21){
                    break;
                }
            }
        }

        if($Cumulative < 17){
            // Hit
            $ZeroPadCard = sprintf("%02d", $DeckPointer);
            $CardIndexStr .= $ZeroPadCard;
            $DeckPointer++;

            // Update the UserHand and DeckPointer
            DBUpdateDealerHand($GameID, $CardIndexStr);
            DBUpdateDeckPointer($GameID, $DeckPointer);
        }if($Cumulative >= 17){
            // Either stay or bust
            $HandInformation = DetermineHandValue($CardIndexArr, $CurrentDeckArr);
            $Cumulative = $HandInformation[0];
            $NumAce = $HandInformation[1];

            echo "DEALERS HAND: " . $Cumulative . "\nNumAces: " . $NumAce . "\n";
            CheckWinners();
            break;
        }
    }
}

function CheckWinners(){
    // Cycle through all hands, check if dealer beats, pushes or loses
    // Make sure to ignore all valx > 21 (those who busted)
    // Update hands lost / hands won
    // Call NewHand
    
    // output dealer hand
    
}

function UpdateUserTurn(){
    // Increment, if no user, dealers turn, in which case to dealer, calc results
    $Username = $_SESSION["Username"];
    $GameID = $_SESSION["GameID"];

    $Index = DBGetUserGameIndex($Username, $GameID);
    $NextPlayer = DBGetUserGameUsernameByIndex($Index + 1, $GameID);

    // If NextPlayer is null, its the dealers turn
    if($NextPlayer === DEALERS_TURN){
        // Dealer Turn
        DBUpdateUserTurn(NULL, $GameID);
        // Trigger dealer turn
        DealerPlay();
    }else{
        DBUpdateUserTurn($NextPlayer, $GameID);
    }

}

function ResetEntireDatabase(){
    // Add some sort of authentication here
    DBTruncateAllTables();
}

?>
