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
    /** @var callable|null */
    private $municipioResolver = null;
    /** @var array<string, string> código IBGE => "Nome / UF" já conhecidos pelo XML */
    private $municipiosConhecidos = [];
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

    /**
     * Resolve o nome do município a partir do código IBGE (cMun) do endereço de
     * tomador, intermediário e destinatário. Recebe o código e devolve
     * "Nome / UF" (ou string vazia para manter o padrão).
     */
    public function setMunicipioResolver(callable $resolver)
    {
        $this->municipioResolver = $resolver;
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
            'finNFSe' => (string)($ibsDps->finNFSe ?? ''),
            'ibsNfsePresent' => $ibsNfse !== null && $ibsNfse->count() > 0,
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
            'tomador' => $this->parsePerson($toma, false, '', ''),
            'tomadorPresent' => $toma !== null && (isset($toma->CNPJ) || isset($toma->CPF) || isset($toma->NIF) || isset($toma->xNome)),
            'intermediario' => $this->parsePerson($interm, false, '', ''),
            'intermediarioPresent' => $interm !== null && (isset($interm->CNPJ) || isset($interm->CPF) || isset($interm->NIF) || isset($interm->xNome)),
            'destinatario' => $this->parsePerson($dest, false, '', ''),
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
                'vDedRed' => $this->num($valoresDps->vDedRed->vDR ?? null),
                'vCalcDR' => $this->num($valoresNfse->vCalcDR ?? null),
                'tpBM' => (string)($valoresNfse->tpBM ?? ''),
                'vCalcBMNfse' => $this->num($valoresNfse->vCalcBM ?? null),
                'vDescIncond' => $this->num($valoresDps->vDescCondIncond->vDescIncond ?? null),
                'vDescCond' => $this->num($valoresDps->vDescCondIncond->vDescCond ?? null),
                'vBC' => $this->num($valoresNfse->vBC ?? null),
                'pAliqAplic' => $this->num($valoresNfse->pAliqAplic ?? ($tribMun->pAliq ?? null)),
                'pAliqAplicNfse' => $this->num($valoresNfse->pAliqAplic ?? null),
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
                'piscofinsPresent' => $piscofins !== null && $piscofins->count() > 0,
            ],
            'ibscbs' => [
                'CST' => (string)($gIBSCBS->CST ?? ''),
                'cClassTrib' => (string)($gIBSCBS->cClassTrib ?? ''),
                'cIndOp' => (string)($ibsDps->cIndOp ?? ''),
                'cLocalidadeIncid' => (string)($ibsNfse->cLocalidadeIncid ?? ''),
                'xLocalidadeIncid' => (string)($ibsNfse->xLocalidadeIncid ?? ''),
                'vExclusoes' => null, // computed below
                'vBC' => $this->num($ibsValores->vBC ?? null),
                'vCalcReeRepRes' => $this->num($ibsValores->vCalcReeRepRes ?? null),
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
                'xItemPed' => $this->joinItemPed($serv->infoCompl->gItemPed ?? null),
                'xOutInf' => (string)($serv->infoCompl->xOutInf ?? ''),
                'inscImobIbs' => (string)($ibsDps->imovel->inscImobFisc ?? ''),
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

        // Município / UF de tomador, intermediário e destinatário: o XML só traz o
        // cMun do endereço. xLocIncid é o local de incidência do ISSQN e pode ser
        // outro município, então só é usado quando o código é o mesmo.
        $conhecidos = [];
        $this->municipiosConhecidos = &$conhecidos;
        if ($emitCmun !== '' && $xLocEmi !== '') {
            $conhecidos[$emitCmun] = $xLocEmi . ($emitUf !== '' ? ' / ' . $emitUf : '');
        }
        $cLocIncid = (string)($infNFSe->cLocIncid ?? '');
        if ($cLocIncid !== '' && $xLocIncid !== '' && !isset($conhecidos[$cLocIncid])) {
            $conhecidos[$cLocIncid] = $xLocIncid . ($cLocIncid === $emitCmun && $emitUf !== '' ? ' / ' . $emitUf : '');
        }
        foreach (['tomador', 'intermediario', 'destinatario'] as $parte) {
            $cMunParte = (string)($this->data[$parte]['cMun'] ?? '');
            if ($cMunParte === '') {
                continue;
            }
            $nome = $this->resolveMunicipioDisplay($cMunParte);
            if ($nome === $cMunParte) {
                $nome = '';
            }
            if ($nome !== '') {
                $this->data[$parte]['municipioUf'] = $nome;
            }
        }
        $this->data['destinatarioEhTomador'] = $this->destinatarioEhTomador($toma, $dest);
        $this->data['ibscbsPresent'] = $ibsDps !== null && $ibsDps->count() > 0;

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

        // NT 008 item 2.4.3: título e subtítulo em negrito 9 pt; em homologação,
        // "NFS-e SEM VALIDADE JURÍDICA" em negrito 9 pt, vermelho, logo abaixo.
        $homologacao = ($this->data['tpAmb'] ?? '') === '2';
        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->SetXY($x + 55, $y + ($homologacao ? 1.0 : 2.6));
        $this->pdf->Cell(90, 3.3, 'DANFSe v2.0', 0, 0, 'C');
        $this->pdf->SetXY($x + 55, $y + ($homologacao ? 4.4 : 6.0));
        $this->pdf->Cell(90, 3.3, 'Documento Auxiliar da NFS-e', 0, 0, 'C');
        if ($homologacao) {
            $this->pdf->SetTextColor(255, 0, 0);
            $this->pdf->SetXY($x + 55, $y + 7.8);
            $this->pdf->Cell(90, 3.3, 'NFS-e SEM VALIDADE JURÍDICA', 0, 0, 'C');
            $this->pdf->SetTextColor(0, 0, 0);
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
        $this->drawField($x, $y0, $this->full, $hChave, 'CHAVE DE ACESSO DA NFS-E', $this->data['chaveAcesso'] ?? '', [
            'labelSize' => $this->bodyFont['labelDestaque'],
            'valueSize' => $this->bodyFont['valorDestaque'],
        ]);

        $y2 = $y0 + $hChave;

        $chave = $this->data['chaveAcesso'] ?? '';
        $qrUrl = self::consultaPublicaUrl((string) $chave);
        // NT 008 item 2.4.3: QR Code de no mínimo 1,52 cm, ao lado da chave de
        // acesso, com a legenda em 3 linhas de 6 pt logo abaixo.
        $qrSize = 15.2;
        $qrX = $x + $leftW + ($qrW - $qrSize) / 2;
        $qrY = $y0 + 0.6;
        $this->pdf->write2DBarcode($qrUrl, 'QRCODE,H', $qrX, $qrY, $qrSize, $qrSize, [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
        ], 'N');
        $this->pdf->SetFont('helvetica', '', 6);
        $legenda = [
            'A autenticidade desta NFS-e pode ser verificada',
            'pela leitura deste código QR ou pela consulta da',
            'chave de acesso no portal nacional da NFS-e',
        ];
        foreach ($legenda as $n => $linha) {
            $this->pdf->SetXY($x + $leftW, $qrY + $qrSize + 0.6 + $n * 2.3);
            $this->pdf->Cell($qrW, 2.3, $linha, 0, 0, 'C');
        }
        $this->resetBodyFont();

        // Row: número / competência / emissão NFSe
        $this->drawField($x, $y2, $c, $hRow, 'NÚMERO DA NFS-E', $this->data['numeroNfse'] ?? '');
        $this->drawField($x + $c, $y2, $c, $hRow, 'COMPETÊNCIA DA NFS-E', $this->data['dps']['competencia'] ?? '');
        $this->drawField($x + 2 * $c, $y2, $c, $hRow, 'DATA E HORA DA EMISSÃO DA NFS-E', $this->data['dhProc'] ?? '');

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
        // NT 008 itens 2.3.1/2.3.2, como no gerador oficial da Receita Federal.
        $ind = $this->data['indDest'] ?? '';
        $temTomador = !empty($this->data['tomadorPresent']);
        if (!empty($this->data['destinatarioPresent'])) {
            if ($temTomador && !empty($this->data['destinatarioEhTomador'])) {
                $this->drawMessageBlock('O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO');
                return;
            }
            $this->addPersonBlock('DESTINATÁRIO DA OPERAÇÃO', $this->data['destinatario'], false);
            return;
        }
        if ($temTomador && !empty($this->data['ibscbsPresent']) && $ind === '0') {
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

        $codNac = trim((string) ($s['cTribNac'] ?? ''));
        $codMun = trim((string) ($s['cTribMun'] ?? ''));
        if ($codNac === '') {
            $cod = $codMun;
        } else {
            $cod = $this->formatCodTribNac($codNac) . ($codMun !== '' ? ' / ' . $codMun : '');
        }

        $localFull = $this->joinWithDash(
            $this->data['localPrestacao'] ?? '',
            $this->ufFromIbge((string) ($s['cLocPrestacao'] ?? '')),
            $this->paisOuBr((string) ($s['cPaisPrestacao'] ?? ''))
        );

        $descCod = ($this->data['xTribMun'] ?? '') !== ''
            ? $this->data['xTribMun']
            : ($this->data['xTribNac'] ?? '');

        $y = $this->drawRow($y, [
            [$c, 'SERVIÇO PRESTADO', '', ['fill' => true, 'titleMode' => true]],
            [$c, 'Código de Tributação Nacional / Municipal', $cod],
            [$c, 'Código da NBS', self::formatNbs((string) ($s['cNBS'] ?? ''))],
            [$c, 'Local da Prestação / Sigla UF / País', $localFull],
        ], $h);

        if ($descCod !== '') {
            $this->pdf->SetXY($this->margin + 0.6, $y + 0.6);
            $this->pdf->SetFont('helvetica', '', $this->bodyFont['textoLivre']);
            $this->pdf->Cell($this->full - 1.2, 2.8, $this->trunc($descCod, 200), 0, 0, 'L');
            $this->resetBodyFont();
            $y += 3.8;
        }

        // Descrição longa quebra em várias linhas, como no gerador oficial
        // (limite de 1.300 caracteres, com reticências).
        $descricao = $this->truncReticencias(trim((string) ($s['descricao'] ?? '')), 1297);
        $this->pdf->SetFont('helvetica', '', $this->bodyFont['valorCampo']);
        $linhas = max(1, min(6, (int) $this->pdf->getNumLines($this->dash($descricao), $this->full - 1.2)));
        $this->resetBodyFont();
        $y = $this->drawRow($y, [
            [$this->full, 'Descrição do Serviço', $descricao],
        ], 3.9 + 2.6 * $linhas);

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

        $incidFull = $this->joinWithDash(
            $this->data['localIncidencia'] ?? '',
            $this->ufFromIbge((string) ($this->data['cLocIncid'] ?? '')),
            $this->paisOuBr((string) ($m['cPaisResult'] ?? ''))
        );

        // Como no gerador oficial: benefício, cálculo do BM, deduções e desconto
        // só aparecem quando algum deles tem valor.
        $calculoBM = $m['vCalcBMNfse'] ?? null;
        if ((float) $calculoBM == 0.0) {
            $calculoBM = $m['vCalcBM'];
        }
        $exibirOpcionais = (int) ($m['tpBM'] ?? 0) > 0
            || (float) ($m['vCalcBMNfse'] ?? 0) > 0
            || (float) ($m['vDedRed'] ?? 0) > 0
            || (float) ($m['vDescIncond'] ?? 0) > 0;

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
            [$c, 'Benefício Municipal', $exibirOpcionais ? $this->mapTpBM($m['tpBM'] ?? '') : '-'],
            [$c, 'Cálculo do BM', $exibirOpcionais ? $this->moneyOrDash($calculoBM) : '-'],
            [$c, 'Total Deduções/Reduções', $exibirOpcionais ? $this->moneyOrDash($m['vDedRed']) : '-'],
            [$c, 'Desconto Incondicionado', $exibirOpcionais ? $this->moneyOrDash($m['vDescIncond']) : '-'],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'BC ISSQN', $this->moneyOrDash($m['vBC'])],
            [$c, 'Alíquota Aplicada', $this->numPctOrDash($this->data['municipal']['pAliqAplicNfse'] ?? null)],
            [$c, 'Retenção do ISSQN', $this->mapTpRetISSQN($m['tpRetISSQN'] ?? '')],
            [$c, 'ISSQN Apurado', $this->moneyOrDash($m['vISSQN'])],
        ], $h);

        $this->strokeSection($y0, $y);
        $this->pdf->SetY($y);
    }

    private function addTributacaoFederal()
    {
        $f = $this->data['federal'];
        $y0 = $this->pdf->GetY();
        $y = $y0;
        $c = $this->col;
        $c2 = $this->col2;
        $h = $this->rowH;

        $pis = '-';
        $cofins = '-';
        $descRet = '-';
        $contribSoc = $this->moneyOrDash($f['vRetCSLL']);
        $tpRet = (string) ($f['tpRetPisCofins'] ?? '');
        if (!empty($f['piscofinsPresent'])) {
            if ($tpRet === '1') {
                // NT 008 v1.02 item 2.4.5: com tpRetPisCofins = 1, PIS/COFINS próprios
                // são zero e as contribuições retidas somam CSLL + PIS + COFINS.
                $pis = $this->brl(0.0);
                $cofins = $this->brl(0.0);
                $contribSoc = $this->brl((float) $f['vRetCSLL'] + (float) $f['vPis'] + (float) $f['vCofins']);
            } else {
                $pis = $this->moneyOrDash($f['vPis']);
                $cofins = $this->moneyOrDash($f['vCofins']);
            }
            $descRet = $this->mapTpRetPisCofins($tpRet);
        }

        $y = $this->drawRow($y, [
            [$c, 'TRIBUTAÇÃO FEDERAL (EXCETO CBS)', '', ['fill' => true, 'titleMode' => true, 'labelSize' => $this->bodyFont['labelSecaoLonga']]],
            [$c, 'IRRF', $this->moneyOrDash($f['vRetIRRF'])],
            [$c, 'Contribuição Previdenciária - Retida', $this->moneyOrDash($f['vRetCP'])],
            [$c, 'Contribuições Sociais - Retidas', $contribSoc],
        ], $h);

        // PIS/COFINS próprios só até a competência de 31/12/2026, como no oficial.
        $compet = (string) ($this->data['dps']['dCompetRaw'] ?? '');
        if ($compet === '' || $compet <= '2026-12-31') {
            $y = $this->drawRow($y, [
                [$c, 'PIS - Débito Apuração Própria', $pis],
                [$c, 'COFINS - Débito Apuração Própria', $cofins],
                [$c2, 'Descrição Contrib. Sociais - Retidas', $descRet],
            ], $h);
        }

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

        // Como no gerador oficial: CST, cClassTrib e indicador só com o grupo
        // IBSCBS na NFS-e e na DPS; o restante vem da NFS-e.
        $temIbsNfse = !empty($this->data['ibsNfsePresent']);
        $temAmbos = $temIbsNfse && !empty($this->data['ibscbsPresent']);
        $cst = $temAmbos ? $this->dash($i['CST'] ?? '') : '-';
        $cClass = $temAmbos ? $this->dash($i['cClassTrib'] ?? '') : '-';
        $cIndOp = $temAmbos ? $this->dash($i['cIndOp'] ?? '') : '-';
        $codLocal = trim((string) ($i['cLocalidadeIncid'] ?? ''));
        $nomeLocal = '-';
        $ufLocal = '-';
        if ($codLocal !== '') {
            $resolvido = $this->resolveMunicipioDisplay($codLocal);
            [$nomeR, $ufR] = array_pad(array_map('trim', explode(' / ', $resolvido, 2)), 2, '');
            $nomeLocal = $this->dash($nomeR !== '' && $nomeR !== $codLocal ? $nomeR : ($i['xLocalidadeIncid'] ?? ''));
            $ufLocal = $this->dash($ufR);
        } elseif (($i['xLocalidadeIncid'] ?? '') !== '') {
            $nomeLocal = (string) $i['xLocalidadeIncid'];
        }

        $exclusoes = null;
        if ($temIbsNfse || $this->data['valores']['vDescIncond'] !== null || $this->data['municipal']['vISSQN'] !== null) {
            $exclusoes = (float) ($this->data['valores']['vDescIncond'] ?? 0)
                + (float) ($this->data['municipal']['vISSQN'] ?? 0)
                + (float) ($this->data['municipal']['vCalcDR'] ?? 0)
                + ($temIbsNfse ? (float) ($i['vCalcReeRepRes'] ?? 0) : 0.0)
                + (!empty($this->data['federal']['piscofinsPresent'])
                    ? (float) ($this->data['federal']['vPis'] ?? 0) + (float) ($this->data['federal']['vCofins'] ?? 0)
                    : 0.0);
        }

        $y = $this->drawRow($y, [
            [$c, 'TRIBUTAÇÃO IBS / CBS', '', ['fill' => true, 'titleMode' => true]],
            [$c, 'CST / cClassTrib', $this->joinValues($cst, $cClass)],
            [$c2, 'Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF',
                $this->joinValues($cIndOp, $this->dash($codLocal), $nomeLocal, $ufLocal)],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'Exclusões e Reduções da Base de Cálculo', $this->moneyOrDash($exclusoes)],
            [$c, 'Base de Cálculo Após Exclusões e Reduções', $this->moneyOrDash($i['vBC'])],
            [$c, 'Red. Alíquota IBS / Red. Alíquota CBS', $this->joinValues(
                $this->pctOrDash($i['pRedAliqUF']),
                $this->pctOrDash($i['pRedAliqMun']),
                $this->pctOrDash($i['pRedAliqCBS'])
            )],
            [$c, 'Alíquota IBS UF / IBS Mun', $this->joinValues($this->pctOrDash($i['pIBSUF']), $this->pctOrDash($i['pIBSMun']))],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'Alíq. Efetiva Estadual - IBS', $this->pctOrDash($i['pAliqEfetUF'])],
            [$c, 'Valor Apurado Estadual - IBS', $this->moneyOrDash($i['vIBSUF'])],
            [$c, 'Alíq. Efetiva Municipal - IBS', $this->pctOrDash($i['pAliqEfetMun'])],
            [$c, 'Valor Apurado Municipal - IBS', $this->moneyOrDash($i['vIBSMun'])],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'Valor Total Apurado - IBS', $this->moneyOrDash($i['vIBSTot'])],
            [$c, 'Alíquota - CBS', $this->pctOrDash($i['pCBS'])],
            [$c, 'Alíquota Efetiva - CBS', $this->pctOrDash($i['pAliqEfetCBS'])],
            [$c, 'Valor Total Apurado - CBS', $this->moneyOrDash($i['vCBS'])],
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

        $totIbsCbs = '-';
        $vLiqIbs = '-';
        if (!empty($this->data['ibsNfsePresent'])) {
            $soma = (float) ($i['vIBSTot'] ?? 0) + (float) ($i['vCBS'] ?? 0);
            $totIbsCbs = $this->moneyOrDash($soma);
            $vLiqIbs = $this->moneyOrDash($i['vTotNF']);
        }

        $y = $this->drawRow($y, [
            [$c, 'VALOR TOTAL DA NFS-E', '', ['fill' => true, 'titleMode' => true]],
            [$c, 'VALOR DA OPERAÇÃO / SERVIÇO', $this->brl((float) ($v['vServ'] ?? 0)), ['valueBold' => false]],
            [$c, 'Desconto Incondicionado', $this->moneyOrDash($v['vDescIncond'])],
            [$c, 'Desconto Condicionado', $this->moneyOrDash($v['vDescCond'])],
        ], $h);

        $y = $this->drawRow($y, [
            [$c, 'Total das Retenções (ISSQN / Federais)', $this->moneyOrDash($v['vTotalRet'])],
            [$c, 'VALOR LÍQUIDO DA NFS-e', $this->brl((float) ($v['vLiq'] ?? 0)), ['valueBold' => false]],
            [$c, 'Total do IBS/CBS', $totIbsCbs],
            [$c, 'VALOR LÍQUIDO DA NFS-e + IBS/CBS', $vLiqIbs, [
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
        $inscImob = trim((string) ($ic['inscImobFisc'] ?? ''));
        if ($inscImob === '') {
            $inscImob = trim((string) ($ic['inscImobIbs'] ?? ''));
        }
        $campos = [
            'Inf. Cont.:' => $ic['xInfComp'] ?? '',
            'NFS-e Subst.:' => $ic['chSubstda'] ?? '',
            'Doc. Ref.:' => $ic['docRef'] ?? '',
            'Cod. Obra:' => $ic['cObra'] ?? '',
            'Insc. Imob.:' => $inscImob,
            'Cod. Evt.:' => $ic['idAtvEvt'] ?? '',
            'Doc. Tec.:' => $ic['idDocTec'] ?? '',
            'Núm. Ped.:' => $ic['xPed'] ?? '',
            'Item Ped.:' => $ic['xItemPed'] ?? '',
            'Inf. A. T. Mun.:' => $ic['xOutInf'] ?? '',
        ];
        $parts = [];
        foreach ($campos as $rotulo => $valor) {
            $valor = trim((string) $valor);
            if ($valor !== '') {
                $parts[] = $rotulo . ' ' . $valor;
            }
        }

        // Como no gerador oficial: texto limitado a 2.000 caracteres, com a
        // linha dos totais aproximados sempre preservada no fim.
        $totaisLine = $this->buildTotaisAproximadosLine();
        $base = implode(' | ', $parts);
        if ($base === '') {
            $body = $totaisLine;
        } else {
            $body = $this->truncReticencias($base, 1997) . ' | ' . $totaisLine;
        }

        $this->fillGray($x, $y, $c, $h);
        $this->strokeRect($x, $y, $this->full, $h);
        // Title at top of gray cell, single line (portal)
        $this->pdf->SetFont('helvetica', 'B', $this->bodyFont['infoLabel']);
        $this->pdf->SetXY($x + 0.5, $y + 0.6);
        $this->pdf->Cell($c - 1, 2.2, 'INFORMAÇÕES COMPLEMENTARES', 0, 0, 'L');

        $this->pdf->SetXY($x + $c + 0.5, $y + 0.6);
        $this->pdf->SetFont('helvetica', '', $this->bodyFont['infoCorpo']);
        $this->pdf->MultiCell($this->full - $c - 1, 2.1, $body, 0, 'L', false, 0);
        $this->resetBodyFont();
        $this->pdf->SetY($y + $h);
    }

    private function buildTotaisAproximadosLine()
    {
        $ic = $this->data['infoCompl'];
        $texto = 'Totais aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: %s; Estaduais: %s; Municipais: %s;';
        $vFed = (float) ($ic['vTotTribFed'] ?? 0);
        $vEst = (float) ($ic['vTotTribEst'] ?? 0);
        $vMun = (float) ($ic['vTotTribMun'] ?? 0);
        if ($vFed > 0 || $vEst > 0 || $vMun > 0) {
            return sprintf($texto, $this->brl($vFed), $this->brl($vEst), $this->brl($vMun));
        }
        $pFed = (float) ($ic['pTotTribFed'] ?? 0);
        $pEst = (float) ($ic['pTotTribEst'] ?? 0);
        $pMun = (float) ($ic['pTotTribMun'] ?? 0);
        if ($pFed > 0 || $pEst > 0 || $pMun > 0) {
            return sprintf($texto, $this->pct($pFed), $this->pct($pEst), $this->pct($pMun));
        }

        return sprintf($texto, 'R$ 0,00', 'R$ 0,00', 'R$ 0,00');
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
            [$c, 'DATA CIENTIFICAÇÃO:', '', ['allowEmpty' => true]],
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
        // NT 008 item 2.4.5, nota 12: campo sem informação no XML leva traço.
        if ($v === null) {
            return '-';
        }
        return number_format((float)$v, 2, ',', '.');
    }

    private function percent($v, $zeroAsZero = false)
    {
        if ($v === null) {
            return '-';
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
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $n);
        }
        if (strlen($n) === 10) {
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $n);
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
        // Leiaute (TSAmbGeradorNFSe): 1 - Prefeitura; 2 - Sistema Nacional da NFS-e.
        $m = ['1' => 'Prefeitura', '2' => 'Sistema Nacional da NFS-e'];
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
            '100' => 'NFS-e Regular',
            '101' => 'NFS-e Cancelada',
            '102' => 'NFS-e Substituída',
            '103' => 'NFS-e de Decisão Judicial ou Administrativa',
            '104' => 'NFS-e Emitida em Contingência',
            '105' => 'NFS-e de Ajuste',
        ];
        return $m[(string) $v] ?? '-';
    }

    private function mapFinNFSe($v)
    {
        $m = [
            '0' => 'NFS-e regular',
            '1' => 'NFS-e de substituição',
            '2' => 'NFS-e de ajuste',
        ];
        return $m[(string) $v] ?? '-';
    }

    private function mapOpSimpNac($v)
    {
        $m = [
            '1' => '1 - Não Optante',
            '2' => '2 - Optante - MEI',
            '3' => '3 - Optante - ME/EPP',
        ];
        return $m[(string) $v] ?? '-';
    }

    private function mapRegApTribSN($v)
    {
        $m = [
            '1' => '1 - Regime de apuração dos tributos federais e municipal pelo SN',
            '2' => '2 - Regime de apuração dos tributos federais pelo SN e ISSQN por fora do SN',
        ];
        return $m[(string) $v] ?? '-';
    }

    private function mapTribISSQN($v)
    {
        $m = [
            '1' => 'Operação Tributável',
            '2' => 'Imunidade',
            '3' => 'Exportação de Serviço',
            '4' => 'Não Incidência',
        ];
        return $m[(string) $v] ?? '-';
    }

    private function mapRegEspTrib($v)
    {
        if ((string) $v === '') {
            $v = '0';
        }
        $m = [
            '0' => 'Nenhum',
            '1' => 'Ato Cooperado (Cooperativa)',
            '2' => 'Estimativa',
            '3' => 'Microempresa Municipal',
            '4' => 'Notário ou Registrador',
            '5' => 'Profissional Autônomo',
            '6' => 'Sociedade de Profissionais',
            '9' => 'Outros',
        ];
        return $m[(string) $v] ?? '-';
    }

    private function mapTpImunidade($v)
    {
        $m = [
            '1' => 'Patrimônio, renda ou serviços, uns dos outros (CF88, Art 150, VI, a)',
            '2' => 'Templos de qualquer culto (CF88, Art 150, VI, b)',
            '3' => 'Patrimônio, renda ou serviços dos partidos políticos, inclusive suas fundações, das entidades sindicais dos trabalhadores, das instituições de educação e de assistência social, sem fins lucrativos, atendidos os requisitos da lei (CF88, Art 150, VI, c)',
            '4' => 'Livros, jornais, periódicos e o papel destinado a sua impressão (CF88, Art 150, VI, d)',
            '5' => 'Fonogramas e videofonogramas musicais produzidos no Brasil contendo obras musicais ou literomusicais de autores brasileiros e/ou obras em geral interpretadas por artistas brasileiros bem como os suportes materiais ou arquivos digitais que os contenham, salvo na etapa de replicação industrial de mídias ópticas de leitura a laser.   (CF88, Art 150, VI, e)',
        ];
        return $m[(string) $v] ?? '-';
    }

    private function mapTpSusp($v)
    {
        $m = [
            '1' => 'Exigibilidade Suspensa por Decisão Judicial',
            '2' => 'Exigibilidade Suspensa por Processo Administrativo',
        ];
        return $m[(string) $v] ?? '-';
    }

    private function mapTpRetISSQN($v)
    {
        $m = [
            '1' => 'Não Retido',
            '2' => 'Retido pelo Tomador',
            '3' => 'Retido pelo Intermediário',
        ];
        return $m[(string) $v] ?? '-';
    }

    private function mapTpRetPisCofins($v)
    {
        $m = [
            '0' => 'PIS/COFINS/CSLL Não Retidos',
            '1' => 'PIS/COFINS Retidos',
            '2' => 'PIS/COFINS Não Retidos',
            '3' => 'PIS/COFINS/CSLL Retidos',
            '4' => 'PIS/COFINS Retidos, CSLL Não Retido',
            '5' => 'PIS Retido, COFINS/CSLL Não Retido',
            '6' => 'COFINS Retido, PIS/CSLL Não Retido',
            '7' => 'PIS Não Retido, COFINS/CSLL Retidos',
            '8' => 'PIS/COFINS Não Retidos, CSLL Retido',
            '9' => 'COFINS Não Retido, PIS/CSLL Retidos',
        ];
        return $m[(string) $v] ?? '-';
    }

    // ---- Formatação igual ao gerador oficial da Receita Federal ----

    /** NT 008 item 2.4.3: endereço do QR Code. */
    public static function consultaPublicaUrl($chave)
    {
        return 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=' . $chave;
    }

    /** NBS no formato 9.9999.99.99, como no gerador oficial. */
    public static function formatNbs($value)
    {
        $digits = preg_replace('/\D/', '', (string)$value);
        if (strlen($digits) !== 9) {
            return (string)$value;
        }
        return substr($digits, 0, 1) . '.' . substr($digits, 1, 4) . '.' . substr($digits, 5, 2) . '.' . substr($digits, 7);
    }

    private function destinatarioEhTomador($toma, $dest)
    {
        if ($toma === null || $dest === null) {
            return false;
        }
        foreach (['CNPJ', 'CPF', 'NIF'] as $tag) {
            $a = trim((string)($toma->{$tag} ?? ''));
            if ($a !== '' && $a === trim((string)($dest->{$tag} ?? ''))) {
                return true;
            }
        }
        return false;
    }

    // ---- Formatação igual ao gerador oficial da Receita Federal ----

    /** "R$ 1.234,56" (FormatarValorMonetario). */
    private function brl(float $v): string
    {
        return 'R$ ' . ($v < 0 ? '-' : '') . number_format(abs($v), 2, ',', '.');
    }

    /** Traço quando ausente ou zero (FormatarValorMonetarioOuTraco). */
    private function moneyOrDash($v): string
    {
        if ($v === null || (float) $v == 0.0) {
            return '-';
        }

        return $this->brl((float) $v);
    }

    private function pct(float $v): string
    {
        return number_format($v, 2, ',', '') . '%';
    }

    /** Traço quando ausente ou zero (FormatarPercentualOuTraco). */
    private function pctOrDash($v): string
    {
        if ($v === null || (float) $v == 0.0) {
            return '-';
        }

        return $this->pct((float) $v);
    }

    /** Sem o sinal de % (FormatarNumeroPercentualOuTraco). */
    private function numPctOrDash($v): string
    {
        if ($v === null || (float) $v == 0.0) {
            return '-';
        }

        return number_format((float) $v, 2, ',', '');
    }

    /** Junta valores já formatados com " / "; tudo vazio vira um traço só. */
    private function joinValues(string ...$values): string
    {
        $parts = [];
        $temInformacao = false;
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                $value = '-';
            }
            if ($value !== '-') {
                $temInformacao = true;
            }
            $parts[] = $value;
        }

        return $temInformacao ? implode(' / ', $parts) : '-';
    }

    /** Como joinValues, para textos crus (combinarComBarra). */
    private function joinWithDash(string ...$values): string
    {
        return $this->joinValues(...$values);
    }

    private function paisOuBr(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));

        return $codigo !== '' ? $codigo : 'BR';
    }

    /** UF do município pelo código IBGE; vazio quando não encontrado. */
    private function ufFromIbge(string $codigo): string
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            return '';
        }
        $emit = $this->data['emitente'] ?? [];
        if (($emit['cMun'] ?? '') === $codigo && ($emit['uf'] ?? '') !== '') {
            return (string) $emit['uf'];
        }
        $resolvido = $this->resolveMunicipioDisplay($codigo);
        $pos = strrpos($resolvido, ' / ');

        return $pos === false ? '' : trim(substr($resolvido, $pos + 3));
    }

    private function truncReticencias(string $texto, int $limite): string
    {
        if (mb_strlen($texto) <= $limite) {
            return $texto;
        }

        return rtrim(mb_substr($texto, 0, $limite), " \n") . '...';
    }

    private function joinItemPed($grupos): string
    {
        $itens = [];
        if ($grupos !== null) {
            foreach ($grupos as $grupo) {
                $item = trim((string) ($grupo->xItemPed ?? ''));
                if ($item !== '') {
                    $itens[] = $item;
                }
            }
        }

        return implode(', ', $itens);
    }

    private function mapTpBM($v)
    {
        $m = [
            '1' => 'Isenção',
            '2' => "Redução da BC em 'ppBM' %'",
            '3' => "Redução da BC em R$ 'vInfoBM'",
            '4' => "Alíquota Diferenciada de 'aliqDifBM' %",
        ];
        return $m[(string) $v] ?? '-';
    }

    /** "Nome / UF" pelo código IBGE: resolvedor informado, depois os municípios do próprio XML; senão o código. */
    private function resolveMunicipioDisplay($codigo)
    {
        $codigo = trim((string)$codigo);
        if ($codigo === '') {
            return '';
        }
        if ($this->municipioResolver !== null) {
            $nome = trim((string)call_user_func($this->municipioResolver, $codigo));
            if ($nome !== '') {
                return $nome;
            }
        }
        return $this->municipiosConhecidos[$codigo] ?? $codigo;
    }
}
