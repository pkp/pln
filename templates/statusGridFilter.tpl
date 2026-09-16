{**
 * templates/statusGridFilter.tpl
 *
 * Copyright (c) 2016 Simon Fraser University
 * Copyright (c) 2016 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Filter form for the PN deposits grid.
 *}
{assign var="formId" value="plnDepositsFilter-"|concat:$filterData.gridId}
<script>
	$('#{$formId}').pkpHandler('$.pkp.controllers.form.ClientFormHandler', {ldelim}
		trackFormChanges: false
	{rdelim});
</script>
<form class="pkp_form filter" id="{$formId}" action="{url op="fetchGrid"}" method="post">
	{csrf}
	{fbvFormArea id="plnDepositsSearchFormArea"|concat:$filterData.gridId}
		{fbvFormSection}
			{fbvElement type="search" name="search" id="search" value=$filterSelectionData.search label="common.search" size=$fbvStyles.size.MEDIUM inline="true"}
			{fbvElement type="select" name="status" id="status" from=$filterData.statuses selected=$filterSelectionData.status label="common.status" size=$fbvStyles.size.SMALL translate=false inline="true"}
		{/fbvFormSection}
		{fbvFormButtons hideCancel=true submitText="common.search"}
	{/fbvFormArea}
</form>
