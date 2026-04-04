<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reporting CNPS</title>
    <style>
        body { 
            font-family: sans-serif; 
            font-size: 10px; 
            color: #000; 
        }
        
        .filters-section {
            margin-bottom: 10px;
            font-size: 11px;
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        
        th, td { 
            border: 1px solid #000; 
            padding: 5px 4px; /* Légèrement plus d'espace vertical pour aérer */
            text-align: left; 
            vertical-align: middle;
        }

        .table-header-blue {
            background-color: #9bc2e6; 
            font-weight: bold;
            text-align: center;
            font-size: 11px;
            padding: 8px;
        }

        th { 
            background-color: #bdd7ee; 
            font-size: 10px;
            text-align: center;
            font-weight: bold;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .font-bold { font-weight: bold; }
        .bg-light { background-color: #f2f2f2; }
    </style>
</head>
<body>

    <div class="filters-section">
        <strong>Début période :</strong> {{ $request->start_date ? \Carbon\Carbon::parse($request->start_date)->format('d/m/Y') : '---' }} &nbsp;&nbsp;&nbsp;&nbsp;
        <strong>Fin période :</strong> {{ $request->end_date ? \Carbon\Carbon::parse($request->end_date)->format('d/m/Y') : '---' }}
    </div>

    <table>
        <thead>
            <tr>
                <td colspan="10" class="table-header-blue">
                    PERIODE DU {{ $request->start_date ? \Carbon\Carbon::parse($request->start_date)->format('d/m/Y') : 'DEBUT' }} AU {{ $request->end_date ? \Carbon\Carbon::parse($request->end_date)->format('d/m/Y') : "JOUR D'HUI" }}
                </td>
            </tr>
            <tr>
                <th width="3%">N°</th>
                <th width="8%">Date</th>
                <th width="12%">N° Employeur</th>
                <th width="20%">Raison Sociale</th>
                <th width="12%">Banque</th>
                <th width="10%">Mode</th>
                <th width="15%">Réf. Paiement</th>
                <th width="5%">Centre</th>
                <th width="5%">Type</th>
                <th width="10%">Montant</th>
            </tr>
        </thead>
        <tbody>
            @foreach($declarations as $index => $dec)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-center">{{ $dec->created_at->format('d/m/Y') }}</td>
                <td class="text-center">{{ $dec->company->niu ?? 'N/A' }}</td>
                <td>{{ $dec->company->raison_sociale ?? 'N/A' }}</td>
                
                <td class="text-center">{{ $dec->bank ? $dec->bank->bank_name : 'N/A' }}</td>
                <td class="text-center">{{ strtoupper($dec->payment_mode ?? '-') }}</td>
                
                <td class="text-center">{{ $dec->reference }}</td>
                <td class="text-center">310</td>
                <td class="text-center">EM</td>
                
                <td class="text-right font-bold">{{ number_format($dec->amount, 0, ',', ' ') }}</td>
            </tr>
            @endforeach
            
            <tr>
                <td colspan="9" class="text-right font-bold bg-light" style="padding-right: 15px;">TOTAL GENERAL (FCFA)</td>
                <td class="text-right font-bold bg-light">{{ number_format($totalAmount, 0, ',', ' ') }}</td>
            </tr>
        </tbody>
    </table>

</body>
</html>