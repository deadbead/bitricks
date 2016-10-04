<?
function custom_mail($to, $subject, $message, $additional_headers, $additional_parameters)
{
    $to = preg_replace_callback('#=\?.+\?(.+)\?=#', function ($m) {
        return base64_decode($m[1]);
    }, $to);

    $subject = preg_replace_callback('#=\?.+\?(.+)\?=#', function ($m) {
        return base64_decode($m[1]);
    }, $subject);

    $additional_headers = preg_replace_callback('#=\?.+\?(.+)\?=#', function ($m) {
        return base64_decode($m[1]);
    }, $additional_headers);

    if (preg_match('#X-MID: (\d+)\.(\d+)#s', $additional_headers, $m))
        if ($eventMessage = \Bitrix\Main\Mail\Internal\EventMessageTable::getRowById($m[2]))
            if ($eventMessage['BODY_TYPE'] == 'text')
                $message = str_replace("\n", '<br />', $message);

    $GLOBALS["CUSTOM_MAIL_ARRAY"] = array(
        "TO" => htmlspecialchars($to),
        "SUBJ" => htmlspecialchars($subject),
        "BODY" => $message,
        "HEADERS" => $additional_headers,
        "PARAMS" => $additional_parameters,
    );
}

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
if (!$USER->IsAdmin())
    ShowError('Требуются права администратора');
else {
    ?>
    <style>
        .find {
            color: red;
        }

        .mail {
            border: 1px solid #c76a00;
            background-color: #fffccc;
            padding: 5px;
            margin-bottom: 10px;
        }

        label {
            font-weight: 700;
        }

        select#status {
            width: 41px;
        }
    </style>
    <?

    $curPage = $APPLICATION->GetCurPageParam();
    $iPage = isset($_REQUEST["page"]) ? $_REQUEST["page"] : 1;
    $strFilter = isset($_REQUEST["filter"]) ? $_REQUEST["filter"] : 'SMQ';
    $strStatus = isset($_REQUEST["status"]) ? $_REQUEST["status"] : '*';
    $iPPage = isset($_REQUEST["ppage"]) ? $_REQUEST["ppage"] : 10;

    $arStatus = [
        '*' => '(Любой)',
        '!' => 'проблемы с отправкой',
        'Y' => 'все письма по всем почтовым шаблонам были успешно отправлены',
        'F' => 'все письма по всем почтовым шаблонам не смогли быть отправлены',
        'P' => 'часть писем отправлена успешно, часть писем - безуспешно',
        '0' => 'почтовые шаблоны не были найдены',
        'N' => 'почтовое событие ещё не обрабатывалось функцией CEvent::CheckEvents',
    ];

    $pOfset = ($iPage - 1) * $iPPage;
    $sqlFilter = 'WHERE 1=1';
    $sqlFilter .= (empty($strFilter) ? '' : "\nAND C_FIELDS LIKE '%{$strFilter}%'");
    $sqlFilter .= ($strStatus === '*' ? '' : "\nAND SUCCESS_EXEC " . ($strStatus === '!' ? "NOT IN ('Y', 'N')" : "= '{$strStatus}'"));

    $rsc = $DB->Query("SELECT 'x' FROM b_event {$sqlFilter}");
    $rowCount = $rsc->AffectedRowsCount();
    ?>
    <form target="_self" class="mail">
        <label for="status">Статус:</label>
        <select id="status" name="status">
            <? foreach ($arStatus as $k => $v) {
                ?>
                <option value="<?= $k ?>"<?= ((string)$k === $strStatus ? ' selected' : '') ?>>[<?= $k ?>]
                    - <?= $v ?></option>
                <?
            } ?>
        </select>
        <label for="filter">Фильр:</label> <input id="filter" type="text" name="filter"
                                                  value="<?= $strFilter ?>"/><input type="submit" value="Go">
        <label for="page">Страница:</label>
        <?
        $d = 3;
        $tPage = intval($rowCount / $iPPage);

        $ib = $iPage - $d;
        if ($ib < 1) $ib = 1;
        $ie = $iPage + $d;
        if ($ie > $tPage) $ie = $tPage;

        if ($iPage > 1) {
            ?>
            <span><a href="<?= CHTTP::urlAddParams($curPage, array("page" => $iPage - 1)) ?>" target="_self">
                    &lt;</a></span>
            <?
        }
        if ($iPage - $d > 1)
            echo '<span>...</span>';

        for ($i = $ib; $i <= $ie; $i++) {
            if ($iPage == $i) {
                ?>
                <span><input style="width: 20px; text-align: center;" id="page" type="text" name="page"
                             value="<?= $iPage ?>" size="1"/></span>
                <?
            } else {
                ?>
                <span><a href="<?= CHTTP::urlAddParams($curPage, array("page" => $i)) ?>"
                         target="_self"><?= $i ?></a></span>
                <?
            }
        }

        if ($iPage + $d < $tPage) {
            ?>
            <span>...</span> <span><a href="<?= CHTTP::urlAddParams($curPage, array("page" => $tPage)) ?>"
                                      target="_self"><?= $tPage ?></a></span>
            <?
        }
        if ($iPage < $tPage) {
            ?>
            <span><a href="<?= CHTTP::urlAddParams($curPage, array("page" => $iPage + 1)) ?>" target="_self">
                    &gt;</a></span>
            <?
        }

        ?>
        <label for="page">Записей на страницу:</label> <input id="ppage" type="text" name="ppage" value="<?= $iPPage ?>"
                                                              size="1"/>
    </form>
    <?


    $strSql = " SELECT ID, C_FIELDS, EVENT_NAME, MESSAGE_ID, LID, DATE_FORMAT(DATE_INSERT, '%d.%m.%Y %H:%i:%s') as DATE_INSERT, DUPLICATE, SUCCESS_EXEC
			FROM b_event
			{$sqlFilter}
			ORDER BY ID DESC
			LIMIT {$pOfset}, {$iPPage}";

    $rsMails = $DB->Query($strSql);

    while ($arMail = $rsMails->Fetch()) {
        if ($arMail['LID'] == 'ru') $arMail['LID'] = 's1';
        $arMail["FIELDS"] = unserialize($arMail['C_FIELDS']);
        \Bitrix\Main\Mail\Event::handleEvent($arMail);
        $arMail["MAIL"] = $GLOBALS["CUSTOM_MAIL_ARRAY"];

        $out = "<div class='mail'>[<b>{$arMail[SUCCESS_EXEC]}</b>], <span title='{$arMail[MAIL][HEADERS]}'><b>Date:</b> {$arMail[DATE_INSERT]}, <b>To:</b> {$arMail[MAIL][TO]}, <b>Subj:</b> {$arMail[MAIL][SUBJ]}</span><hr>{$arMail[MAIL][BODY]}</div>";

        if (!empty($strFilter))
            $out = str_replace($strFilter, "<b class=find>{$strFilter}</b>", $out);

        echo $out;
    }
}

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php");