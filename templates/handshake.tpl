{**
 * templates/handshake.tpl
 *
 * Copyright (c) 2016 Simon Fraser University
 * Copyright (c) 2016 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Handshake template
 *}
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE handshake SYSTEM "handshake.dtd">
<handshake>
	<ojsInfo>
		<release>{$ojsVersion|escape}</release>
	</ojsInfo>
	<pluginInfo>
		<release>{$pluginVersion.release|escape}</release>
		<releaseDate>{$pluginVersion.date|escape}</releaseDate>
		<current>{$pluginVersion.version->getCurrent()|escape}</current>
		<php>{$phpVersion|escape}</php>
		<zipArchive>{$hasZipArchive|escape}</zipArchive>
		<terms termsAccepted="{$termsAccepted|escape}">
			{iterate from=termsDisplay item=term}
			<term key="{$term.key|escape}" updated="{$term.updated|escape}" accepted="{$term.accepted|escape}">{$term.term|escape}</term>
			{/iterate}
		</terms>
	</pluginInfo>
	<network>{$networkUrl|escape}</network>
	<journalInfo>
		<title>{$currentJournal->getLocalizedName()|escape}</title>
		<articles count="{$publications|@count|escape}">
			{foreach from=$publications item=publication}
			<article pubDate="{$publication->getData('datePublished')|escape}">{$publication->getLocalizedTitle()|escape}</article>
			{/foreach}
		</articles>
	</journalInfo>
</handshake>
