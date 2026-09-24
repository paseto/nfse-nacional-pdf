<?php

namespace NfsePdf;

use TCPDF;

class NfsePdfGenerator
{
    private $pdf;
    private $data = [];
    /** @var float margin in mm — NT 2.4.5: 0,30 cm */
    private $margin = 2.0;
    /** @var float column width — NT: 5,09 cm */
    private $col = 50.9;
    /** @var float double column — NT: 10,19 cm */
    private $col2 = 101.9;
    /** @var float full content width — NT: 20,40 cm */
    private $full = 204.0;
    /** @var float default row height — NT: 0,63 cm */
    private $rowH = 6.3;
    private $logoSvg = null;
    private $showCanhoto = true;
    private $contentWidth;
    private $gray = [242, 242, 242];
    private $borderColor = [200, 200, 200];
    private $lineW = 0.12;

    /**
     * Tipografia do corpo da DANFSe (pt) — ajuste centralizado.
     *
     * labelSecao: títulos capitalizados na faixa cinza (PRESTADOR / SERVIÇO PRESTADO)
     * labelCampo: rótulos dos campos filhos (NÚMERO DA NFS-e, CNPJ / CPF…)
     * valorDestaque: valores em destaque no topo do corpo (ex.: chave de acesso)
     * valorCampo: valores dos campos filhos
     * espacoLabelValor: espaço vertical (mm) entre o label e o value nos campos
     */
    private $bodyFont = [
        'labelSecao' => 6,
        'labelCampo' => 6,
        'valorDestaque' => 7.0,
        'valorCampo' => 6.5,
        'labelSecaoLonga' => 6,
        'labelDestaque' => 6.0,
        'valorCompacto' => 6.0,
        'textoLivre' => 7.5,
        'mensagem' => 6.5,
        'infoLabel' => 5.0,
        'infoCorpo' => 5.0,
        'qrLegenda' => 4,
        'espacoLabelValor' => 1.0,
    ];

    public function __construct()
    {
        $this->pdf = new class('P', 'mm', 'A4', true, 'UTF-8', false) extends TCPDF {
            public function __construct($orientation, $unit, $format, $unicode, $encoding, $diskcache)
            {
                parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache);
                $this->tcpdflink = false;
            }

            public function Header()
            {
            }

            public function Footer()
            {
            }
        };
        $this->pdf->SetCreator('NFS-e PDF Generator');
        $this->pdf->SetAuthor('NFS-e System');
        $this->pdf->SetTitle('DANFSe');
        $this->pdf->SetSubject('Documento Auxiliar da NFS-e');
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins($this->margin, $this->margin, $this->margin);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetDrawColor($this->borderColor[0], $this->borderColor[1], $this->borderColor[2]);
        $this->pdf->SetLineWidth($this->lineW);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->contentWidth = $this->full;
    }

    /**
     * Optional municipal SVG (kept for compatibility; not used in v2.0 header).
     */
    public function setLogoSvg(string $svgContent)
    {
        $this->logoSvg = $svgContent;
        return $this;
    }

    /**
     * @deprecated Header now uses Município / Ambiente Gerador / Tipo de Ambiente from XML.
     */
    public function setHeaderInfo(array $headerInfo)
    {
        return $this;
    }

    public function setShowCanhoto(bool $show)
    {
        $this->showCanhoto = $show;
        return $this;
    }

    public function parseXml($xmlFile)
    {
        $xml = simplexml_load_file($xmlFile);
        if ($xml === false) {
            throw new \Exception('Failed to parse XML file');
        }

        $ns = $xml->getNamespaces(true);
        $nsUri = $ns[''] ?? null;
        $infNFSe = $nsUri ? $xml->children($nsUri)->infNFSe : $xml->infNFSe;
        $dpsNode = $nsUri ? $infNFSe->children($nsUri)->DPS : $infNFSe->DPS;
        $dps = $nsUri ? $dpsNode->children($nsUri)->infDPS : $dpsNode->infDPS;

        $id = (string)$infNFSe->attributes()->Id;
        $chaveAcesso = preg_replace('/^NFS/', '', $id);

        $emit = $infNFSe->emit ?? null;
        $toma = $dps->toma ?? null;
        $interm = $dps->interm ?? null;
        $serv = $dps->serv ?? null;
        $valoresDps = $dps->valores ?? null;
        $valoresNfse = $infNFSe->valores ?? null;
        $ibsNfse = $infNFSe->IBSCBS ?? null;
        $ibsDps = $dps->IBSCBS ?? null;

        $emitUf = (string)($emit->enderNac->UF ?? '');
        $emitCmun = (string)($emit->enderNac->cMun ?? '');
        $xLocEmi = (string)($infNFSe->xLocEmi ?? '');
        $xLocPrestacao = (string)($infNFSe->xLocPrestacao ?? '');
        $xLocIncid = (string)($infNFSe->xLocIncid ?? '');

        $regTrib = $dps->prest->regTrib ?? null;
        $opSimpNac = (string)($regTrib->opSimpNac ?? '');
        $regApTribSN = (string)($regTrib->regApTribSN ?? '');
        $regApIBSCBSSN = (string)($regTrib->regApIBSCBSSN ?? '');
        $regEspTrib = (string)($regTrib->regEspTrib ?? '');

        $tribMun = $valoresDps->trib->tribMun ?? null;
        $tribFed = $valoresDps->trib->tribFed ?? null;
        $piscofins = $tribFed->piscofins ?? null;
        $totTrib = $valoresDps->trib->totTrib ?? null;

        $gIBSCBS = $ibsDps->valores->trib->gIBSCBS ?? null;
        $ibsValores = $ibsNfse->valores ?? null;
        $totCIBS = $ibsNfse->totCIBS ?? null;
        $dest = $ibsDps->dest ?? null;
        $indDest = (string)($ibsDps->indDest ?? '');

        $this->data = [
            'chaveAcesso' => $chaveAcesso,
            'numeroNfse' => (string)($infNFSe->nNFSe ?? ''),
            'localEmissao' => $xLocEmi,
            'localPrestacao' => $xLocPrestacao,
            'localIncidencia' => $xLocIncid,
            'cLocIncid' => (string)($infNFSe->cLocIncid ?? ''),
            'xTribNac' => (string)($infNFSe->xTribNac ?? ''),
            'xTribMun' => (string)($infNFSe->xTribMun ?? ''),
            'xNBS' => (string)($infNFSe->xNBS ?? ''),
            'ambGer' => (string)($infNFSe->ambGer ?? ''),
            'tpEmis' => (string)($infNFSe->tpEmis ?? ''),
            'cStat' => (string)($infNFSe->cStat ?? ''),
            'dhProc' => $this->formatDateTime((string)($infNFSe->dhProc ?? '')),
            'xOutInf' => (string)($infNFSe->xOutInf ?? ''),
            'tpAmb' => (string)($dps->tpAmb ?? ''),
            'tpEmit' => (string)($dps->tpEmit ?? ''),
            'finNFSe' => (string)($dps->finNFSe ?? ''),
            'cLocEmi' => (string)($dps->cLocEmi ?? $emitCmun),
            'dps' => [
                'numero' => (string)($dps->nDPS ?? ''),
                'serie' => (string)($dps->serie ?? ''),
                'competencia' => $this->formatDate((string)($dps->dCompet ?? '')),
                'dataEmissao' => $this->formatDateTime((string)($dps->dhEmi ?? '')),
                'dCompetRaw' => (string)($dps->dCompet ?? ''),
            ],
            'emitente' => $this->parsePerson($emit, true, $xLocEmi, $emitUf),
            'prestador' => $this->parsePerson($dps->prest ?? null, false, $xLocEmi, $emitUf),
            'tomador' => $this->parsePerson($toma, false, $xLocIncid ?: $xLocEmi, $emitUf),
            'tomadorPresent' => $toma !== null && (isset($toma->CNPJ) || isset($toma->CPF) || isset($toma->NIF) || isset($toma->xNome)),
            'intermediario' => $this->parsePerson($interm, false, '', $emitUf),
            'intermediarioPresent' => $interm !== null && (isset($interm->CNPJ) || isset($interm->CPF) || isset($interm->NIF) || isset($interm->xNome)),
            'destinatario' => $this->parsePerson($dest, false, '', $emitUf),
            'destinatarioPresent' => $dest !== null && (isset($dest->CNPJ) || isset($dest->CPF) || isset($dest->NIF) || isset($dest->xNome)),
            'indDest' => $indDest,
            'opSimpNac' => $opSimpNac,
            'regApTribSN' => $regApTribSN,
            'regApIBSCBSSN' => $regApIBSCBSSN,
            'regEspTrib' => $regEspTrib,
            'servico' => [
                'cTribNac' => (string)($serv->cServ->cTribNac ?? ''),
                'cTribMun' => (string)($serv->cServ->cTribMun ?? ''),
                'cNBS' => (string)($serv->cServ->cNBS ?? ''),
                'descricao' => (string)($serv->cServ->xDescServ ?? ''),
                'cLocPrestacao' => (string)($serv->locPrest->cLocPrestacao ?? ''),
                'cPaisPrestacao' => (string)($serv->locPrest->cPaisPrestacao ?? ''),
            ],
            'municipal' => [
                'present' => $tribMun !== null,
                'tribISSQN' => (string)($tribMun->tribISSQN ?? ''),
                'cPaisResult' => (string)($tribMun->cPaisResult ?? ''),
                'tpImunidade' => (string)($tribMun->tpImunidade ?? ''),
                'tpSusp' => (string)($tribMun->exigSusp->tpSusp ?? ''),
                'nProcesso' => (string)($tribMun->exigSusp->nProcesso ?? ''),
                'nBM' => (string)($tribMun->BM->nBM ?? ''),
                'vCalcBM' => $this->num($valoresNfse->vCalcBM ?? ($tribMun->BM->vRedBCBM ?? null)),
                'vDedRed' => $this->num($valoresDps->vAjusteBC->vAjusteBCISSQN ?? null),
                'vDescIncond' => $this->num($valoresDps->vDescCondIncond->vDescIncond ?? null),
                'vDescCond' => $this->num($valoresDps->vDescCondIncond->vDescCond ?? null),
                'vBC' => $this->num($valoresNfse->vBC ?? null),
                'pAliqAplic' => $this->num($valoresNfse->pAliqAplic ?? ($tribMun->pAliq ?? null)),
                'tpRetISSQN' => (string)($tribMun->tpRetISSQN ?? ''),
                'vISSQN' => $this->num($valoresNfse->vISSQN ?? null),
            ],
            'federal' => [
                'vRetIRRF' => $this->num($tribFed->vRetIRRF ?? null),
                'vRetCP' => $this->num($tribFed->vRetCP ?? null),
                'vRetCSLL' => $this->num($tribFed->vRetCSLL ?? null),
                'vPis' => $this->num($piscofins->vPis ?? null),
                'vCofins' => $this->num($piscofins->vCofins ?? null),
                'tpRetPisCofins' => (string)($piscofins->tpRetPisCofins ?? ''),
            ],
            'ibscbs' => [
                'CST' => (string)($gIBSCBS->CST ?? ''),
                'cClassTrib' => (string)($gIBSCBS->cClassTrib ?? ''),
                'cIndOp' => (string)($ibsDps->cIndOp ?? ''),
                'cLocalidadeIncid' => (string)($ibsNfse->cLocalidadeIncid ?? ''),
                'xLocalidadeIncid' => (string)($ibsNfse->xLocalidadeIncid ?? ''),
                'vExclusoes' => null, // computed below
                'vBC' => $this->num($ibsValores->vBC ?? null),
                'pRedAliqUF' => $this->num($ibsValores->uf->pRedAliqUF ?? null),
                'pRedAliqMun' => $this->num($ibsValores->mun->pRedAliqMun ?? null),
                'pRedAliqCBS' => $this->num($ibsValores->fed->pRedAliqCBS ?? null),
                'pIBSUF' => $this->num($ibsValores->uf->pIBSUF ?? null),
                'pIBSMun' => $this->num($ibsValores->mun->pIBSMun ?? null),
                'pAliqEfetUF' => $this->num($ibsValores->uf->pAliqEfetUF ?? null),
                'pAliqEfetMun' => $this->num($ibsValores->mun->pAliqEfetMun ?? null),
                'vIBSUF' => $this->num($totCIBS->gIBS->gIBSUFTot->vIBSUF ?? null),
                'vIBSMun' => $this->num($totCIBS->gIBS->gIBSMunTot->vIBSMun ?? null),
                'vIBSTot' => $this->num($totCIBS->gIBS->vIBSTot ?? null),
                'pCBS' => $this->num($ibsValores->fed->pCBS ?? null),
                'pAliqEfetCBS' => $this->num($ibsValores->fed->pAliqEfetCBS ?? null),
                'vCBS' => $this->num($totCIBS->gCBS->vCBS ?? null),
                'vTotNF' => $this->num($totCIBS->vTotNF ?? null),
            ],
            'valores' => [
                'vServ' => $this->num($valoresDps->vServPrest->vServ ?? null),
                'vDescIncond' => $this->num($valoresDps->vDescCondIncond->vDescIncond ?? null),
                'vDescCond' => $this->num($valoresDps->vDescCondIncond->vDescCond ?? null),
                'vTotalRet' => $this->num($valoresNfse->vTotalRet ?? null),
                'vLiq' => $this->num($valoresNfse->vLiq ?? null),
            ],
            'infoCompl' => [
                'xInfComp' => (string)($serv->infoCompl->xInfComp ?? ''),
                'docRef' => (string)($serv->infoCompl->docRef ?? ''),
                'xPed' => (string)($serv->infoCompl->xPed ?? ''),
                'idDocTec' => (string)($serv->infoCompl->idDocTec ?? ''),
                'cObra' => (string)($serv->obra->cObra ?? ''),
                'inscImobFisc' => (string)($serv->obra->inscImobFisc ?? ''),
                'idAtvEvt' => (string)($serv->atvEvento->idAtvEvt ?? ''),
                'chSubstda' => (string)($dps->subst->chSubstda ?? ''),
                'pTotTribFed' => $this->num($totTrib->pTotTrib->pTotTribFed ?? null),
                'pTotTribEst' => $this->num($totTrib->pTotTrib->pTotTribEst ?? null),
                'pTotTribMun' => $this->num($totTrib->pTotTrib->pTotTribMun ?? null),
                'vTotTribFed' => $this->num($totTrib->vTotTrib->vTotTribFed ?? null),
                'vTotTribEst' => $this->num($totTrib->vTotTrib->vTotTribEst ?? null),
                'vTotTribMun' => $this->num($totTrib->vTotTrib->vTotTribMun ?? null),
            ],
        ];

        // Prefer emitente data from infNFSe/emit for display (has full address)
        if (!empty($this->data['emitente']['nome'])) {
            $p = $this->data['prestador'];
            $e = $this->data['emitente'];
            $this->data['prestador'] = array_merge($p, array_filter([
                'doc' => $e['doc'] ?: null,
                'nome' => $e['nome'] ?: null,
                'IM' => $e['IM'] !== '' ? $e['IM'] : null,
                'fone' => $e['fone'] !== '' ? $e['fone'] : null,
                'email' => $e['email'] !== '' ? $e['email'] : null,
                'endereco' => $e['endereco'] !== '' ? $e['endereco'] : null,
                'municipioUf' => $e['municipioUf'] !== '' ? $e['municipioUf'] : null,
                'ibgeCep' => $e['ibgeCep'] !== '' ? $e['ibgeCep'] : null,
                'cMun' => $e['cMun'] !== '' ? $e['cMun'] : null,
                'uf' => $e['uf'] !== '' ? $e['uf'] : null,
            ], function ($v) {
                return $v !== null;
            }));
        }

        // Exclusões BC = somatório NT (descontos + ISSQN + PIS + COFINS + ajustes)
        $excl = 0.0;
        $hasExcl = false;
        foreach ([
            $this->data['valores']['vDescIncond'],
            $this->data['municipal']['vISSQN'],
            $this->data['federal']['vPis'],
            $this->data['federal']['vCofins'],
        ] as $n) {
            if ($n !== null) {
                $excl += $n;
                $hasExcl = true;
            }
        }
        $this->data['ibscbs']['vExclusoes'] = $hasExcl ? $excl : null;

        // Tomador municipio: resolve name from xLoc when only cMun
        if ($this->data['tomadorPresent'] && $this->data['tomador']['municipioUf'] === '' && $xLocIncid) {
            $uf = $this->data['tomador']['uf'] ?: $emitUf;
            $this->data['tomador']['municipioUf'] = trim($xLocIncid . ($uf ? ' / ' . $uf : ''));
        }

        return $this;
    }

    private function parsePerson($node, $isEmit = false, $municipioNome = '', $fallbackUf = '')
    {
        if ($node === null) {
            return [
                'doc' => '', 'IM' => '', 'fone' => '', 'nome' => '', 'email' => '',
                'endereco' => '', 'municipioUf' => '', 'ibgeCep' => '', 'cMun' => '', 'uf' => '',
            ];
        }

        $doc = '';
        if (isset($node->CNPJ) && (string)$node->CNPJ !== '') {
            $doc = $this->formatCnpjCpf((string)$node->CNPJ);
        } elseif (isset($node->CPF) && (string)$node->CPF !== '') {
            $doc = $this->formatCnpjCpf((string)$node->CPF);
        } elseif (isset($node->NIF) && (string)$node->NIF !== '') {
            $doc = (string)$node->NIF;
        }

        $end = null;
        $cMun = '';
        $cep = '';
        $uf = $fallbackUf;
        $xCidade = '';
        $xLgr = $nro = $xCpl = $xBairro = '';

        if ($isEmit && isset($node->enderNac)) {
            $e = $node->enderNac;
            $xLgr = (string)($e->xLgr ?? '');
            $nro = (string)($e->nro ?? '');
            $xCpl = (string)($e->xCpl ?? '');
            $xBairro = (string)($e->xBairro ?? '');
            $cMun = (string)($e->cMun ?? '');
            $uf = (string)($e->UF ?? $fallbackUf);
            $cep = (string)($e->CEP ?? '');
        } elseif (isset($node->end)) {
            $end = $node->end;
            $xLgr = (string)($end->xLgr ?? '');
            $nro = (string)($end->nro ?? '');
            $xCpl = (string)($end->xCpl ?? '');
            $xBairro = (string)($end->xBairro ?? '');
            if (isset($end->endNac)) {
                $cMun = (string)($end->endNac->cMun ?? '');
                $cep = (string)($end->endNac->CEP ?? '');
            } elseif (isset($end->endExt)) {
                $cep = (string)($end->endExt->cEndPost ?? '');
                $xCidade = (string)($end->endExt->xCidade ?? '');
                $uf = (string)($end->endExt->xEstProvReg ?? $fallbackUf);
            }
        }

        $parts = array_filter([$xLgr, $nro, $xCpl, $xBairro], function ($p) {
            return $p !== '';
        });
        $endereco = implode(', ', $parts);

        $munNome = $municipioNome ?: $xCidade;
        $municipioUf = '';
        if ($munNome !== '') {
            $municipioUf = $munNome . ($uf !== '' ? ' / ' . $uf : '');
        } elseif ($cMun !== '') {
            $municipioUf = $cMun . ($uf !== '' ? ' / ' . $uf : '');
        }

        $ibgeCep = '';
        if ($cMun !== '' || $cep !== '') {
            $ibgeCep = trim($cMun . ($cMun && $cep ? ' / ' : '') . ($cep !== '' ? $this->formatCep($cep) : ''));
        }

        return [
            'doc' => $doc,
            'IM' => (string)($node->IM ?? ''),
            'fone' => $this->formatPhone((string)($node->fone ?? '')),
            'nome' => (string)($node->xNome ?? ''),
            'email' => (string)($node->email ?? ''),
            'endereco' => $endereco,
            'municipioUf' => $municipioUf,
            'ibgeCep' => $ibgeCep,
            'cMun' => $cMun,
            'uf' => $uf,
        ];
    }

    public function generate()
    {
        $this->pdf->AddPage();
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetDrawColor($this->borderColor[0], $this->borderColor[1], $this->borderColor[2]);
        $this->pdf->SetLineWidth($this->lineW);
        $this->pdf->SetTextColor(0, 0, 0);

        $this->addHeader();
        $this->addIdentificacao();
        $this->addPrestador();
        $this->addTomador();
        $this->addDestinatario();
        $this->addIntermediario();
        $this->addServico();
        $this->addTributacaoMunicipal();
        $this->addTributacaoFederal();
        $this->addTributacaoIbscbs();
        $this->addValores();
        $this->addInfoComplementares();
        if ($this->showCanhoto) {
            $this->addCanhoto();
        }

        $this->drawWatermark();
        $this->drawDocumentBorder();

        return $this->pdf;
    }

    private function drawDocumentBorder()
    {
        $this->pdf->SetDrawColor($this->borderColor[0], $this->borderColor[1], $this->borderColor[2]);
        $this->pdf->SetLineWidth($this->lineW);
        $m = $this->margin;
        $this->pdf->Rect($m, $m, 210 - 2 * $m, 297 - 2 * $m, 'D');
    }

    private function drawWatermark()
    {
        $cStat = $this->data['cStat'] ?? '';
        $text = '';
        if (in_array($cStat, ['101', '103'], true) || stripos((string)$cStat, 'cancel') !== false) {
            $text = 'CANCELADA';
        } elseif (in_array($cStat, ['102', '104'], true) || stripos((string)$cStat, 'subst') !== false) {
            $text = 'SUBSTITUÍDA';
        }
        if ($text === '') {
            return;
        }
        $this->pdf->StartTransform();
        $this->pdf->SetAlpha(0.35);
        $this->pdf->SetTextColor(90, 90, 90);
        $this->pdf->SetFont('helvetica', 'B', 50);
        $this->pdf->Rotate(45, 105, 148);
        $this->pdf->Text(40, 140, $text);
        $this->pdf->StopTransform();
        $this->pdf->SetAlpha(1);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', '', 8);
    }

    private function fillGray($x, $y, $w, $h)
    {
        $this->pdf->SetFillColor($this->gray[0], $this->gray[1], $this->gray[2]);
        $this->pdf->Rect($x, $y, $w, $h, 'F');
    }

    private function strokeRect($x, $y, $w, $h)
    {
        $this->pdf->SetDrawColor($this->borderColor[0], $this->borderColor[1], $this->borderColor[2]);
        $this->pdf->SetLineWidth($this->lineW);
        $this->pdf->Rect($x, $y, $w, $h, 'D');
    }

    /** Horizontal rule across content width (official portal style). */
    private function hLine($y, $x = null, $w = null)
    {
        $x = $x ?? $this->margin;
        $w = $w ?? $this->full;
        $this->pdf->SetDrawColor($this->borderColor[0], $this->borderColor[1], $this->borderColor[2]);
        $this->pdf->SetLineWidth($this->lineW);
        $this->pdf->Line($x, $y, $x + $w, $y);
    }

    /** Left + right outer verticals for a row band. */
    private function vOuters($y, $h)
    {
        $this->pdf->SetDrawColor($this->borderColor[0], $this->borderColor[1], $this->borderColor[2]);
        $this->pdf->SetLineWidth($this->lineW);
        $x0 = $this->margin;
        $x1 = $this->margin + $this->full;
        $this->pdf->Line($x0, $y, $x0, $y + $h);
        $this->pdf->Line($x1, $y, $x1, $y + $h);
    }

    /**
     * Label top-left + value below. Portal: no per-cell boxes; values regular weight.
     */
    private function drawField($x, $y, $w, $h, $label, $value, $options = [])
    {
        $border = $options['border'] ?? false;
        $fill = !empty($options['fill']);
        $valueBold = !empty($options['valueBold']);
        $align = $options['align'] ?? 'L';
        $titleMode = !empty($options['titleMode']);
        $labelSize = $options['labelSize'] ?? ($titleMode
            ? $this->bodyFont['labelSecao']
            : $this->bodyFont['labelCampo']);
        $valueSize = $options['valueSize'] ?? $this->bodyFont['valorCampo'];

        if ($fill) {
            $this->fillGray($x, $y, $w, $h);
        }
        if ($border) {
            $this->strokeRect($x, $y, $w, $h);
        }

        $pad = 0.6;
        if ($titleMode) {
            $this->pdf->SetFont('helvetica', 'B', $labelSize);
            $txt = $label !== '' ? $label : (string)$value;
            $this->pdf->SetXY($x + $pad, $y + ($h - 2.8) / 2);
            $this->pdf->MultiCell($w - 2 * $pad, 2.5, $txt, 0, 'L', false, 0);
            $this->resetBodyFont();
            return;
        }

        $labelH = 1.8;
        $labelY = $y + 0.35;
        $gap = $options['espacoLabelValor'] ?? $this->bodyFont['espacoLabelValor'];
        $valueY = $labelY + $labelH + $gap;

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetXY($x + $pad, $labelY);
        $this->pdf->SetFont('helvetica', 'B', $labelSize);
        $maxLabel = max(6, (int)(($w - 2 * $pad) / 1.15));
        $this->pdf->Cell($w - 2 * $pad, $labelH, $this->trunc($label, $maxLabel), 0, 0, 'L');

        $allowEmpty = !empty($options['allowEmpty']);
        $val = $allowEmpty ? (string)($value ?? '') : $this->dash($value);
        $this->pdf->SetXY($x + $pad, $valueY);
        $this->pdf->SetFont('helvetica', $valueBold ? 'B' : '', $valueSize);
        $this->pdf->MultiCell($w - 2 * $pad, 2.6, $val, 0, $align, false, 0);
        $this->resetBodyFont();
    }

    private function resetBodyFont()
    {
        $this->pdf->SetFont('helvetica', '', 8);
    }

    /**
     * Draw a row of fields. Portal style: no per-row grid lines (section box drawn separately).
     */
    private function drawRow($y, array $cells, $h = null, $withRules = false)
    {
        $h = $h ?? $this->rowH;
        $x = $this->margin;
        foreach ($cells as $cell) {
            $w = $cell[0];
            $label = $cell[1];
            $value = $cell[2] ?? '';
            $opts = $cell[3] ?? [];
            if (!array_key_exists('border', $opts)) {
                $opts['border'] = false;
            }
            $this->drawField($x, $y, $w, $h, $label, $value, $opts);
            $x += $w;
        }
        if ($withRules) {
            $this->hLine($y);
            $this->hLine($y + $h);
            $this->vOuters($y, $h);
        }
        return $y + $h;
    }

    /** Outer rectangle for a whole section (portal: few lines, open interior). */
    private function strokeSection($y0, $y1)
    {
        $this->strokeRect($this->margin, $y0, $this->full, $y1 - $y0);
    }

    private function drawMessageBlock($message, $h = 5.2)
    {
        $x = $this->margin;
        $y = $this->pdf->GetY();
        $w = $this->full;
        $this->strokeRect($x, $y, $w, $h);
        $this->pdf->SetXY($x, $y + ($h - 2.8) / 2);
        $this->pdf->SetFont('helvetica', '', $this->bodyFont['mensagem']);
        $this->pdf->Cell($w, 2.8, $message, 0, 0, 'C');
        $this->resetBodyFont();
        $this->pdf->SetY($y + $h);
    }

    private function addHeader()
    {
        $x = $this->margin;
        $y = $this->margin;
        $h = 12.0;

        $this->fillGray($x, $y, $this->full, $h);
        $this->strokeRect($x, $y, $this->full, $h);

        $logoPath = __DIR__ . '/../assets/logo-nfse-assinatura-horizontal.png';
        if (file_exists($logoPath)) {
            $this->pdf->Image($logoPath, $x + 1.5, $y + 2.2, 40, 0, 'PNG', '', '', false, 300, '', false, false, 0);
        }

        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->SetXY($x + 55, $y + 2.4);
        $this->pdf->Cell(90, 4, 'DANFSe v2.0', 0, 0, 'C');
        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->pdf->SetXY($x + 55, $y + 6.4);
        $this->pdf->Cell(90, 3, 'Documento Auxiliar da NFS-e', 0, 0, 'C');
        if (($this->data['tpAmb'] ?? '') === '2') {
            $this->pdf->SetFont('helvetica', 'B', 5.5);
            $this->pdf->SetXY($x + 55, $y + 9.4);
            $this->pdf->Cell(90, 2, 'NFS-e SEM VALIDADE JURÍDICA', 0, 0, 'C');
        }

        $mun = $this->data['localEmissao'] ?: '-';
        $uf = $this->data['emitente']['uf'] ?? '';
        $munLine = $uf !== '' && strpos($mun, '/') === false ? ($mun . ' / ' . $uf) : $mun;
        $this->pdf->SetFont('helvetica', '', 6);
        $rx = $x + $this->full - 55;
        $rw = 53;
        $this->pdf->SetXY($rx, $y + 2.0);
        $this->pdf->Cell($rw, 2.5, 'Município: ' . $munLine, 0, 0, 'R');
        $this->pdf->SetXY($rx, $y + 4.7);
        $this->pdf->Cell($rw, 2.5, 'Ambiente Gerador: ' . $this->mapAmbGer($this->data['ambGer'] ?? ''), 0, 0, 'R');
        $this->pdf->SetXY($rx, $y + 7.4);
        $this->pdf->Cell($rw, 2.5, 'Tipo de Ambiente: ' . $this->mapTpAmb($this->data['tpAmb'] ?? ''), 0, 0, 'R');

        $this->pdf->SetY($y + $h);
    }

    private function addIdentificacao()
    {
        $x = $this->margin;
        $y0 = $this->pdf->GetY();
        $c = $this->col;
        $hChave = 7.2;
        $hRow = 6.5;
        $leftW = $c * 3;
        $qrW = $this->full - $leftW;

        // Chave: white background (no gray) — portal style
        $this->drawField($x, $y0, $this->full, $hChave, 'CHAVE DE ACESSO DA NFS-e', $this->data['chaveAcesso'] ?? '', [
            'labelSize' => $this->bodyFont['labelDestaque'],
            'valueSize' => $this->bodyFont['valorDestaque'],
        ]);

        $y2 = $y0 + $hChave;

        $chave = $this->data['chaveAcesso'] ?? '';
        $qrUrl = 'https://www.nfse.gov.br/ConsultaPublica/?chave=' . $chave;
        $qrSize = 16;
        $qrX = $x + $leftW + ($qrW - $qrSize) / 2;
        // High-contrast QR (portal: solid black modules)
        $this->pdf->write2DBarcode($qrUrl, 'QRCODE,H', $qrX, $y2 + 0.4, $qrSize, $qrSize, [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
        ], 'N');
        $this->pdf->SetFont('helvetica', '', $this->bodyFont['qrLegenda']);
        $this->pdf->SetXY($x + $leftW + 0.5, $y2 + $qrSize + 0.7);
        $this->pdf->MultiCell($qrW - 1, 1.5, 'A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e', 0, 'C', false, 0);

        // Row: número / competência / emissão NFSe
        $this->drawField($x, $y2, $c, $hRow, 'NÚMERO DA NFS-e', $this->data['numeroNfse'] ?? '');
        $this->drawField($x + $c, $y2, $c, $hRow, 'COMPETÊNCIA DA NFS-e', $this->data['dps']['competencia'] ?? '');
        $this->drawField($x + 2 * $c, $y2, $c, $hRow, 'DATA E HORA DA EMISSÃO DA NFS-e', $this->data['dhProc'] ?? '');

        $y3 = $y2 + $hRow;
        $this->drawField($x, $y3, $c, $hRow, 'NÚMERO DA DPS', $this->data['dps']['numero'] ?? '');
        $this->drawField($x + $c, $y3, $c, $hRow, 'SÉRIE DA DPS', $this->data['dps']['serie'] ?? '');
        $this->drawField($x + 2 * $c, $y3, $c, $hRow, 'DATA E HORA DA EMISSÃO DA DPS', $this->data['dps']['dataEmissao'] ?? '');

        // Emitente row: only EMITENTE cell is gray (portal); Situação/Finalidade white
        $y4 = $y3 + $hRow;
        $hEmit = 7.2;
        $this->fillGray($x, $y4, $c, $hEmit);
        $this->drawField($x, $y4, $c, $hEmit, 'EMITENTE DA NFS-e', $this->mapTpEmit($this->data['tpEmit'] ?? ''));
        $this->drawField($x + $c, $y4, $c, $hEmit, 'SITUAÇÃO DA NFS-e', $this->mapCStat($this->data['cStat'] ?? ''));
        $this->drawField($x + 2 * $c, $y4, $c, $hEmit, 'FINALIDADE', $this->mapFinNFSe($this->data['finNFSe'] ?? ''));

        $yEnd = $y4 + $hEmit;
        $this->strokeSection($y0, $yEnd);

        $this->pdf->SetY($yEnd);
    }

    private function addPersonBlock($title, array $person, $withSimples = false)
    {
        $y0 = $this->pdf->GetY();
        $y = $y0;
        $c = $this->col;
        $c2 = $this->col2;
        $h = $this->rowH;

        // Portal: only TITLE cell is gray; CNPJ/IM/Telefone on white
        $y = $this->drawRow($y, [
            [$c, $title, '', ['fill' => true, 'titleMode' => true]],
            [$c, 'CNPJ / CPF / NIF', $person['doc'] ?? ''],
            [$c, 'Indicador Municipal (Inscrição)', $person['IM'] ?? ''],
            [$c, 'Telefone', $person['fone'] ?? ''],
        ], $h);

        $y = $this->drawRow($y, [
            [$c2, 'Nome / Nome Empresarial', $person['nome'] ?? ''],
            [$c, 'Município / Sigla UF', $person['municipioUf'] ?? ''],
            [$c, 'Código IBGE / CEP', $person['ibgeCep'] ?? ''],
        ], $h);

        $y = $this->drawRow($y, [
            [$c2, 'Endereço', $person['endereco'] ?? ''],
            [$c2, 'E-mail', $person['email'] ?? ''],
        ], $h);

        if ($withSimples) {
            $y = $this->drawRow($y, [
                [$c2, 'Simples Nacional na Data da Competência', $this->mapOpSimpNac($this->data['opSimpNac'] ?? '')],
                [$c2, 'Regime de Apuração Tributária pelo SN', $this->mapRegApTribSN($this->data['regApTribSN'] ?? $this->data['regApIBSCBSSN'] ?? '')],
            ], $h);
        }

        $this->strokeSection($y0, $y);
        $this->pdf->SetY($y);
    }

    private function addPrestador()
    {
        $this->addPersonBlock('PRESTADOR / FORNECEDOR', $this->data['prestador'], true);
    }

    private function addTomador()
    {
        if (empty($this->data['tomadorPresent'])) {
            $this->drawMessageBlock('TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
            return;
        }
        $this->addPersonBlock('TOMADOR / ADQUIRENTE', $this->data['tomador'], false);
    }

    private function addDestinatario()
    {
        $ind = $this->data['indDest'] ?? '';
        if (!empty($this->data['destinatarioPresent']) && $ind !== '0') {
            $this->addPersonBlock('DESTINATÁRIO DA OPERAÇÃO', $this->data['destinatario'], false);
            return;
        }
        if (!empty($this->data['tomadorPresent']) && (empty($this->data['destinatarioPresent']) || $ind === '0' || $ind === '')) {
            $this->drawMessageBlock('O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO');
            return;
        }
        $this->drawMessageBlock('DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
    }

    private function addIntermediario()
    {
        if (empty($this->data['intermediarioPresent'])) {
            $this->drawMessageBlock('INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
            return;
        }
        $this->addPersonBlock('INTERMEDIÁRIO DA OPERAÇÃO', $this->data['intermediario'], false);
    }

    private function addServico()
    {
        $y0 = $this->pdf->GetY();
        $y = $y0;
        $c = $this->col;
        $h = $this->rowH;
        $s = $this->data['servico'];

        $codNac = $this->formatCodTribNac($s['cTribNac'] ?? '');
        $codMun = $s['cTribMun'] !== '' ? $s['cTribMun'] : '';
        $cod = $this->joinSlash($codNac, $codMun);

        $local = $this->data['localPrestacao'] ?: '';
        $uf = $this->data['emitente']['uf'] ?? '';
        $pais = $s['cPaisPrestacao'] !== '' ? $s['cPaisPrestacao'] : ($local !== '' ? 'BR' : '');
        if ($local !== '' && $uf !== '' && strpos($local, '/') === false) {
            $local = $local . ' / ' . $uf;
        }
        $localFull = $this->joinSlash($local, $pais !== '' ? $pais : null);

        $descCod = ($this->data['xTribMun'] ?? '') !== ''
            ? $this->data['xTribMun']
            : ($this->data['xTribNac'] ?? '');

        $y = $this->drawRow($y, [
            [$c, 'SERVIÇO PRESTADO', '', ['fill' => true, 'titleMode' => true]],
            [$c, 'Código de Tributação Nacional / Municipal', $cod],
            [$c, 'Código da NBS', $s['cNBS'] ?? ''],
            [$c, 'Local da Prestação / Sigla UF / País', $localFull],
        ], $h);

        if ($descCod !== '') {
            $this->pdf->SetXY($this->margin + 0.6, $y + 0.6);
            $this->pdf->SetFont('helvetica', '', $this->bodyFont['textoLivre']);
            $this->pdf->Cell($this->full - 1.2, 2.8, $this->trunc($descCod, 200), 0, 0, 'L');
            $this->resetBodyFont();
            $y += 3.8;
        }

        $y = $this->drawRow($y, [
            [$this->full, 'Descrição do Serviço', $s['descricao'] ?? ''],
        ], 6.5);

        $this->strokeSection($y0, $y);
        $this->pdf->SetY($y);
    }

    private function addTributacaoMunicipal()
    {
        $m = $this->data['municipal'];
        if (empty($m['present']) && ($m['vBC'] === null && ($m['tribISSQN'] ?? '') === '')) {
            $this->drawMessageBlock('TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN');
            return;
        }

        $y0 = $this->pdf->GetY();
        $y = $y0;
        $c = $this->col;
        $c2 = $this->col2;
        $h = $this->rowH;

        $incid = $this->data['localIncidencia'] ?: '';
        $uf = $this->data['emitente']['uf'] ?? '';
        if ($incid !== '' && $uf !== '' && strpos($incid, '/') === false) {
            $incid .= ' / ' . $uf;
        }
        $incidFull = $this->joinSlash($incid, 'BR');

        $y = $this->drawRow($y, [
            [$c, 'TRIBUTAÇÃO MUNICIPAL (ISSQN)', '', ['fill' => true, 'titleMode' => true, 'labelSize' => $this->bodyFont['labelSecaoLonga']]],
            [$c, 'Tipo de Tributação do ISSQN', $this->mapTribISSQN($m['tribISSQN'] ?? '')],
            [$c2, 'Município / Sigla UF / País de Incidência do ISSQN', $incidFull],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'Regime Especial de Tributação do ISSQN', $this->mapRegEspTrib($this->data['regEspTrib'] ?? '')],
            [$c, 'Tipo de Imunidade do ISSQN', $this->mapTpImunidade($m['tpImunidade'] ?? '')],
            [$c, 'Suspensão da Exigibilidade do ISSQN', $this->mapTpSusp($m['tpSusp'] ?? '')],
            [$c, 'Número Processo Suspensão', $m['nProcesso'] ?? ''],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'Benefício Municipal', $m['nBM'] ?? ''],
            [$c, 'Cálculo do BM', $this->money($m['vCalcBM'], true)],
            [$c, 'Total Deduções/Reduções', $this->money($m['vDedRed'], true)],
            [$c, 'Desconto Incondicionado', $this->money($m['vDescIncond'], true)],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'BC ISSQN', $this->money($m['vBC'], true)],
            [$c, 'Alíquota Aplicada %', $this->percent($m['pAliqAplic'], true)],
            [$c, 'Retenção do ISSQN', $this->mapTpRetISSQN($m['tpRetISSQN'] ?? '')],
            [$c, 'ISSQN Apurado', $this->money($m['vISSQN'], true)],
        ], $h);

        $this->strokeSection($y0, $y);
        $this->pdf->SetY($y);
    }

    private function addTributacaoFederal()
    {
        $compet = $this->data['dps']['dCompetRaw'] ?? '';
        $year = (int)substr($compet, 0, 4);
        if ($year > 2026) {
            return;
        }

        $f = $this->data['federal'];
        $y0 = $this->pdf->GetY();
        $y = $y0;
        $c = $this->col;
        $c2 = $this->col2;
        $h = $this->rowH;

        $tpRet = $f['tpRetPisCofins'] ?? '';
        $vPis = ($tpRet === '1') ? 0.0 : $f['vPis'];
        $vCofins = ($tpRet === '1') ? 0.0 : $f['vCofins'];

        $y = $this->drawRow($y, [
            [$c, 'TRIBUTAÇÃO FEDERAL (EXCETO CBS)', '', ['fill' => true, 'titleMode' => true, 'labelSize' => $this->bodyFont['labelSecaoLonga']]],
            [$c, 'IRRF', $this->money($f['vRetIRRF'], true)],
            [$c, 'Contribuição Previdenciária - Retida', $this->money($f['vRetCP'], true)],
            [$c, 'Contribuições Sociais - Retidas', $this->money($f['vRetCSLL'], true)],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'PIS - Débito Apuração Própria', $this->money($vPis, true)],
            [$c, 'COFINS - Débito Apuração Própria', $this->money($vCofins, true)],
            [$c2, 'Descrição Contrib. Sociais - Retidas', $this->mapTpRetPisCofins($tpRet)],
        ], $h);

        $this->strokeSection($y0, $y);
        $this->pdf->SetY($y);
    }

    private function addTributacaoIbscbs()
    {
        $i = $this->data['ibscbs'];
        $y0 = $this->pdf->GetY();
        $y = $y0;
        $c = $this->col;
        $c2 = $this->col2;
        $h = $this->rowH;

        $cst = $this->joinSlash($i['CST'] ?? '', $i['cClassTrib'] ?? '');
        $locNome = $i['xLocalidadeIncid'] ?: ($this->data['localIncidencia'] ?: '');
        $uf = $this->data['emitente']['uf'] ?? '';
        if ($locNome !== '' && $uf !== '' && strpos($locNome, '/') === false) {
            $locNome .= ' / ' . $uf;
        }
        $indOp = implode(' / ', array_filter([
            $i['cIndOp'] ?? '',
            $i['cLocalidadeIncid'] ?: ($this->data['cLocIncid'] ?? ''),
            $locNome,
        ], function ($v) {
            return $v !== '' && $v !== null;
        }));

        $redAliq = $this->joinSlash(
            $this->percent($i['pRedAliqUF'], true),
            $this->joinSlash($this->percent($i['pRedAliqMun'], true), $this->percent($i['pRedAliqCBS'], true))
        );
        $aliqIbs = $this->joinSlash($this->percent($i['pIBSUF'], true), $this->percent($i['pIBSMun'], true));

        $y = $this->drawRow($y, [
            [$c, 'TRIBUTAÇÃO IBS / CBS', '', ['fill' => true, 'titleMode' => true]],
            [$c, 'CST / cClassTrib', $cst],
            [$c2, 'Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF', $indOp],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'Exclusões e Reduções da Base de Cálculo', $this->money($i['vExclusoes'], true)],
            [$c, 'Base de Cálculo Após Exclusões e Reduções', $this->money($i['vBC'], true)],
            [$c, 'Red. Alíquota IBS / Red. Alíquota CBS % / %', $redAliq],
            [$c, 'Alíq. - IBS UF / IBS Mun', $aliqIbs],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'Alíq. Efetiva Estadual - IBS', $this->percent($i['pAliqEfetUF'], true)],
            [$c, 'Valor Total Apurado Estadual - IBS', $this->money($i['vIBSUF'], true)],
            [$c, 'Alíq. Efetiva Municipal - IBS', $this->percent($i['pAliqEfetMun'], true)],
            [$c, 'Valor Total Apurado Municipal - IBS', $this->money($i['vIBSMun'], true)],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'Valor Total Apurado - IBS', $this->money($i['vIBSTot'], true)],
            [$c, 'Alíquota - CBS', $this->percent($i['pCBS'], true)],
            [$c, 'Alíquota Efetiva - CBS', $this->percent($i['pAliqEfetCBS'], true)],
            [$c, 'Valor Total Apurado - CBS', $this->money($i['vCBS'], true)],
        ], $h);

        $this->strokeSection($y0, $y);
        $this->pdf->SetY($y);
    }

    private function addValores()
    {
        $v = $this->data['valores'];
        $i = $this->data['ibscbs'];
        $y0 = $this->pdf->GetY();
        $y = $y0;
        $c = $this->col;
        $h = 6.7;

        $totIbsCbs = null;
        if ($i['vIBSTot'] !== null || $i['vCBS'] !== null) {
            $totIbsCbs = (float)($i['vIBSTot'] ?? 0) + (float)($i['vCBS'] ?? 0);
        }
        $vLiqIbs = $i['vTotNF'];
        if ($vLiqIbs === null && $v['vLiq'] !== null) {
            $vLiqIbs = $v['vLiq'];
        }

        $y = $this->drawRow($y, [
            [$c, 'VALOR TOTAL DA NFS-e', '', ['fill' => true, 'titleMode' => true]],
            [$c, 'VALOR DA OPERAÇÃO / SERVIÇO', $this->money($v['vServ'], true), ['valueBold' => false]],
            [$c, 'Desconto Incondicionado', $this->money($v['vDescIncond'], true)],
            [$c, 'Desconto Condicionado', $this->money($v['vDescCond'], true)],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'Total das Retenções (ISSQN / Federais)', $this->money($v['vTotalRet'], true)],
            [$c, 'VALOR LÍQUIDO DA NFS-e', $this->money($v['vLiq'], true), ['valueBold' => false]],
            [$c, 'Total do IBS/CBS', $this->money($totIbsCbs, true)],
            [$c, 'VALOR LÍQUIDO DA NFS-e + IBS/CBS', $this->money($vLiqIbs, true), [
                'valueBold' => false,
                'fill' => true,
            ]],
        ], $h);

        $this->strokeSection($y0, $y);
        $this->pdf->SetY($y);
    }

    private function addInfoComplementares()
    {
        $x = $this->margin;
        $y = $this->pdf->GetY();
        $canhotoH = $this->showCanhoto ? 10.0 : 0;
        $bottom = 297 - $this->margin - $canhotoH;
        $h = max(8.0, $bottom - $y);
        $c = $this->col;

        $ic = $this->data['infoCompl'];
        $parts = [];
        if ($ic['xInfComp'] !== '') {
            $parts[] = 'Inf. Cont.: ' . $ic['xInfComp'];
        }
        if ($ic['chSubstda'] !== '') {
            $parts[] = 'NFS-e Subst.: ' . $ic['chSubstda'];
        }
        if ($ic['docRef'] !== '') {
            $parts[] = 'Doc. Ref.: ' . $ic['docRef'];
        }
        if ($ic['cObra'] !== '') {
            $parts[] = 'Cod. Obra: ' . $ic['cObra'];
        }
        if ($ic['inscImobFisc'] !== '') {
            $parts[] = 'Insc. Imob.: ' . $ic['inscImobFisc'];
        }
        if ($ic['idAtvEvt'] !== '') {
            $parts[] = 'Cod. Evt.: ' . $ic['idAtvEvt'];
        }
        if ($ic['idDocTec'] !== '') {
            $parts[] = 'Doc. Tec.: ' . $ic['idDocTec'];
        }
        if ($ic['xPed'] !== '') {
            $parts[] = 'Núm. Ped.: ' . $ic['xPed'];
        }
        if (($this->data['xOutInf'] ?? '') !== '') {
            $parts[] = 'Inf. A. T. Mun.: ' . $this->data['xOutInf'];
        }

        $totaisLine = $this->buildTotaisAproximadosLine();
        $body = implode(' | ', $parts);
        $body = $body !== '' ? ($body . "\n" . $totaisLine) : $totaisLine;

        $this->fillGray($x, $y, $c, $h);
        $this->strokeRect($x, $y, $this->full, $h);
        // Title at top of gray cell, single line (portal)
        $this->pdf->SetFont('helvetica', 'B', $this->bodyFont['infoLabel']);
        $this->pdf->SetXY($x + 0.5, $y + 0.6);
        $this->pdf->Cell($c - 1, 2.2, 'INFORMAÇÕES COMPLEMENTARES', 0, 0, 'L');

        $this->pdf->SetXY($x + $c + 0.5, $y + 0.6);
        $this->pdf->SetFont('helvetica', '', $this->bodyFont['infoCorpo']);
        $this->pdf->MultiCell($this->full - $c - 1, 2.1, $this->trunc($body, 1997), 0, 'L', false, 0);
        $this->resetBodyFont();
        $this->pdf->SetY($y + $h);
    }

    private function buildTotaisAproximadosLine()
    {
        $ic = $this->data['infoCompl'];
        $useValor = $ic['vTotTribFed'] !== null || $ic['vTotTribEst'] !== null || $ic['vTotTribMun'] !== null;
        if ($useValor) {
            return sprintf(
                'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: %s; Estaduais: %s; Municipais: %s',
                $this->money($ic['vTotTribFed'] ?? 0, true),
                $this->money($ic['vTotTribEst'] ?? 0, true),
                $this->money($ic['vTotTribMun'] ?? 0, true)
            );
        }
        return sprintf(
            'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: %s; Estaduais: %s; Municipais: %s',
            $this->percent($ic['pTotTribFed'] ?? 0, true),
            $this->percent($ic['pTotTribEst'] ?? 0, true),
            $this->percent($ic['pTotTribMun'] ?? 0, true)
        );
    }

    private function addCanhoto()
    {
        $h = 10.0;
        $y = 297 - $this->margin - $h;
        $c = $this->col;
        $c2 = $this->col2;
        $chaveLine = trim(($this->data['numeroNfse'] ?? '') . ' / ' . ($this->data['chaveAcesso'] ?? ''), ' /');

        $this->pdf->SetY($y);
        $this->drawRow($y, [
            [$c, 'DATA CIENTIFICAÇÃO', '', ['allowEmpty' => true]],
            [$c, 'IDENTIFICAÇÃO E ASSINATURA', '', ['allowEmpty' => true]],
            [$c2, 'Nº NFS-e / CHAVE NFS-e', $chaveLine, ['valueSize' => $this->bodyFont['valorCompacto']]],
        ], $h, true);

        // Official canhoto keeps vertical separators between the 3 fields
        $this->pdf->SetDrawColor($this->borderColor[0], $this->borderColor[1], $this->borderColor[2]);
        $this->pdf->SetLineWidth($this->lineW);
        $this->pdf->Line($this->margin + $c, $y, $this->margin + $c, $y + $h);
        $this->pdf->Line($this->margin + 2 * $c, $y, $this->margin + 2 * $c, $y + $h);

        $this->pdf->SetY($y + $h);
    }

    // --- helpers ---

    private function dash($value)
    {
        if ($value === null) {
            return '-';
        }
        if (is_string($value) && trim($value) === '') {
            return '-';
        }
        return (string)$value;
    }

    private function num($v)
    {
        if ($v === null || $v === '' || !isset($v)) {
            return null;
        }
        if (is_object($v) && !isset($v[0]) && (string)$v === '') {
            return null;
        }
        $s = trim((string)$v);
        if ($s === '') {
            return null;
        }
        return (float)$s;
    }

    private function money($v, $zeroAsZero = false)
    {
        if ($v === null) {
            return $zeroAsZero ? '0,00' : '-';
        }
        return number_format((float)$v, 2, ',', '.');
    }

    private function percent($v, $zeroAsZero = false)
    {
        if ($v === null) {
            return $zeroAsZero ? '0,00%' : '-';
        }
        return number_format((float)$v, 2, ',', '.') . '%';
    }

    private function joinSlash($a, $b)
    {
        $a = ($a === null || $a === '') ? '' : (string)$a;
        $b = ($b === null || $b === '') ? '' : (string)$b;
        if ($a === '' && $b === '') {
            return '';
        }
        if ($a === '') {
            return $b;
        }
        if ($b === '') {
            return $a;
        }
        return $a . ' / ' . $b;
    }

    private function trunc($text, $maxChars)
    {
        $text = (string)$text;
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        return mb_substr($text, 0, max(0, $maxChars - 3)) . '...';
    }

    private function formatCnpjCpf($value)
    {
        $n = preg_replace('/\D/', '', (string)$value);
        if (strlen($n) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $n);
        }
        if (strlen($n) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $n);
        }
        return (string)$value;
    }

    private function formatCep($value)
    {
        $n = preg_replace('/\D/', '', (string)$value);
        if (strlen($n) === 8) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})/', '$1.$2-$3', $n);
        }
        return (string)$value;
    }

    private function formatPhone($value)
    {
        $n = preg_replace('/\D/', '', (string)$value);
        if ($n === '' || preg_match('/^0+$/', $n)) {
            return '';
        }
        if (strlen($n) === 11) {
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2 $3', $n);
        }
        if (strlen($n) === 10) {
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2 $3', $n);
        }
        return (string)$value;
    }

    private function formatDate($value)
    {
        if ($value === '') {
            return '';
        }
        try {
            return (new \DateTime($value))->format('d/m/Y');
        } catch (\Exception $e) {
            return $value;
        }
    }

    private function formatDateTime($value)
    {
        if ($value === '') {
            return '';
        }
        try {
            return (new \DateTime($value))->format('d/m/Y H:i:s');
        } catch (\Exception $e) {
            return $value;
        }
    }

    private function formatCodTribNac($code)
    {
        $n = preg_replace('/\D/', '', (string)$code);
        if (strlen($n) === 6) {
            return substr($n, 0, 2) . '.' . substr($n, 2, 2) . '.' . substr($n, 4, 2);
        }
        return (string)$code;
    }

    private function mapAmbGer($v)
    {
        $m = ['1' => 'Prefeitura', '2' => 'Sefin Nacional', '3' => 'Sistema Nacional'];
        return $m[$v] ?? ($v !== '' ? $v : '-');
    }

    private function mapTpAmb($v)
    {
        $m = ['1' => 'Produção', '2' => 'Homologação'];
        return $m[$v] ?? ($v !== '' ? $v : '-');
    }

    private function mapTpEmit($v)
    {
        $m = [
            '1' => 'Prestador',
            '2' => 'Tomador',
            '3' => 'Intermediário',
        ];
        return $m[$v] ?? ($v !== '' ? $v : '-');
    }

    private function mapCStat($v)
    {
        $m = [
            '100' => 'Normal',
            '101' => 'Cancelada',
            '102' => 'Substituída',
        ];
        return $m[$v] ?? ($v !== '' ? $v : '-');
    }

    private function mapFinNFSe($v)
    {
        $m = [
            '0' => 'NFS-e regular',
            '1' => 'NFS-e regular',
            '2' => 'NFS-e de crédito',
            '3' => 'NFS-e de débito',
        ];
        return $m[$v] ?? ($v !== '' ? $v : '-');
    }

    private function mapOpSimpNac($v)
    {
        $m = [
            '1' => 'Não Optante',
            '2' => 'Optante - Microempreendedor Individual (MEI)',
            '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
        ];
        return $m[$v] ?? ($v !== '' ? $v : '-');
    }

    private function mapRegApTribSN($v)
    {
        $m = [
            '1' => 'Regime de apuração dos tributos federais e municipal pelo SN',
            '2' => 'Regime de apuração dos tributos federais pelo SN e ISSQN pela legislação municipal',
            '3' => 'Regime de apuração dos tributos federais e municipal pela legislação aplicável às demais pessoas jurídicas',
        ];
        return $m[$v] ?? ($v !== '' ? $v : '-');
    }

    private function mapTribISSQN($v)
    {
        $m = [
            '1' => 'Operação tributável',
            '2' => 'Imunidade',
            '3' => 'Exportação de serviço',
            '4' => 'Não Incidência',
        ];
        return $m[$v] ?? ($v !== '' ? $v : '-');
    }

    private function mapRegEspTrib($v)
    {
        if ($v === '' || $v === '0') {
            return '-';
        }
        $m = [
            '1' => 'Ato Cooperado',
            '2' => 'Estimativa',
            '3' => 'Sociedade de profissionais',
            '4' => 'Cooperativa',
            '5' => 'Microempresário Individual (MEI)',
            '6' => 'Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
        ];
        return $m[$v] ?? $v;
    }

    private function mapTpImunidade($v)
    {
        if ($v === '') {
            return '-';
        }
        $m = [
            '1' => 'Patrimônio, renda ou serviços de outros entes',
            '2' => 'Templos de qualquer culto',
            '3' => 'Patrimônio, renda ou serviços de partidos políticos e entidades sindicais',
            '4' => 'Instituições de educação e assistência social',
            '5' => 'Livros, jornais, periódicos e papel',
        ];
        return $m[$v] ?? $v;
    }

    private function mapTpSusp($v)
    {
        if ($v === '') {
            return '-';
        }
        $m = [
            '1' => 'Exigibilidade suspensa por decisão judicial',
            '2' => 'Exigibilidade suspensa por processo administrativo',
        ];
        return $m[$v] ?? $v;
    }

    private function mapTpRetISSQN($v)
    {
        $m = [
            '1' => 'Não Retido',
            '2' => 'Retido pelo Tomador',
            '3' => 'Retido pelo Intermediário',
        ];
        return $m[$v] ?? ($v !== '' ? $v : '-');
    }

    private function mapTpRetPisCofins($v)
    {
        $m = [
            '1' => 'PIS/COFINS Retido',
            '2' => 'PIS/COFINS Não Retido',
            '3' => 'PIS Retido / COFINS Não Retido',
            '4' => 'PIS Não Retido / COFINS Retido',
            '5' => 'PIS/COFINS/CSLL Retido',
            '6' => 'PIS/COFINS/CSLL Não Retido',
            '7' => 'PIS Retido',
            '8' => 'COFINS Retido',
            '9' => 'CSLL Retido',
        ];
        return $m[$v] ?? ($v !== '' ? $v : '-');
    }
}
