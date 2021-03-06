<?php
// sa/sa_get_json.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetJson_SearchAction extends SearchAction {
    private $iszip;
    private $zipdoc;
    public function __construct($iszip) {
        $this->iszip = $iszip;
    }
    public function document_callback($dj, DocumentInfo $doc, $dtype, PaperStatus $pstatus) {
        if ($doc->docclass->load($doc, true)) {
            $dj->content_file = HotCRPDocument::filename($doc);
            $this->zipdoc->add_as($doc, $dj->content_file);
        }
    }
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [1090 + $this->iszip, $this->subname, "Paper information", $this->iszip ? "JSON with attachments" : "JSON"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(["paperId" => $ssel->selection(), "topics" => true, "options" => true]);
        $pj = [];
        $ps = new PaperStatus($user->conf, $user, ["forceShow" => true, "hide_docids" => true]);
        if ($this->iszip) {
            $this->zipdoc = new ZipDocument($user->conf->download_prefix . "data.zip");
            $ps->on_document_export([$this, "document_callback"]);
        }
        foreach (PaperInfo::fetch_all($result, $user) as $prow)
            if ($user->can_administer($prow, true))
                $pj[$prow->paperId] = $ps->paper_json($prow);
            else {
                $pj[$prow->paperId] = (object) ["pid" => $prow->paperId, "error" => "You don’t have permission to administer this paper."];
                if ($this->iszip)
                    $this->zipdoc->warnings[] = "#$prow->paperId: You don’t have permission to administer this paper.";
            }
        $pj = array_values($ssel->reorder($pj));
        if (count($pj) == 1) {
            $pj = $pj[0];
            $pj_filename = $user->conf->download_prefix . "paper" . $ssel->selection_at(0) . "-data.json";
        } else
            $pj_filename = $user->conf->download_prefix . "data.json";
        if ($this->iszip) {
            $this->zipdoc->add(to_json($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", $pj_filename);
            $this->zipdoc->download();
        } else {
            header("Content-Type: application/json");
            header("Content-Disposition: attachment; filename=" . mime_quote_string($pj_filename));
            echo to_json($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        exit;
    }
}

class GetJsonRQC_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [1092, $this->subname, "Paper information", "JSON for reviewqualitycollector.org"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(["paperId" => $ssel->selection(), "topics" => true, "options" => true]);
        $results = ["hotcrp_version" => HOTCRP_VERSION];
        if (($git_data = Conf::git_status()))
            $results["hotcrp_commit"] = $git_data[0];
        $rf = $user->conf->review_form();
        $results["reviewform"] = $rf->unparse_json(0, VIEWSCORE_REVIEWERONLY);
        $pj = [];
        $ps = new PaperStatus($user->conf, $user, ["forceShow" => true, "hide_docids" => true]);
        foreach (PaperInfo::fetch_all($result, $user) as $prow)
            if ($user->can_administer($prow, true)) {
                $pj[$prow->paperId] = $j = $ps->paper_json($prow);
                $prow->ensure_full_reviews();
                $prow->ensure_reviewer_names();
                $prow->ensure_review_ratings();
                $rs = [];
                foreach ($prow->reviews_by_id() as $rrow)
                    if ($rrow->reviewSubmitted > 0
                        && $user->can_view_review($prow, $rrow, true))
                        $rs[] = $rrow;
                usort($rs, "ReviewInfo::compare");
                foreach ($rs as $rrow)
                    $j->reviews[] = $rf->unparse_review_json($prow, $rrow, $user, true, ReviewForm::RJ_NO_EDITABLE | ReviewForm::RJ_UNPARSE_RATINGS | ReviewForm::RJ_ALL_RATINGS | ReviewForm::RJ_NO_REVIEWERONLY);
            } else
                $pj[$prow->paperId] = (object) ["pid" => $prow->paperId, "error" => "You don’t have permission to administer this paper."];
        $pj = array_values($ssel->reorder($pj));
        $results["papers"] = $pj;
        header("Content-Type: application/json");
        header("Content-Disposition: attachment; filename=" . mime_quote_string($user->conf->download_prefix . "rqc.json"));
        echo to_json($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }
}

SearchAction::register("get", "json", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetJson_SearchAction(false));
SearchAction::register("get", "jsonattach", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetJson_SearchAction(true));
SearchAction::register("get", "jsonrqc", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetJsonRQC_SearchAction);
