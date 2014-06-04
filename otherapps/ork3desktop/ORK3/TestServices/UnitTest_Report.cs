using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Reflection;

namespace TestServices
{
    public partial class UnitTest
    {
        public bool Report_GetKingdomParkAverages()
        {
            ReportService.GetKingdomParkAveragesRequest request = new ReportService.GetKingdomParkAveragesRequest() { AverageWeeks = 26 };
            ReportService.GetKingdomParkAveragesResponse response = reportsvc.GetKingdomParkAverages(request);

            if (response.Status.Status == 0 && response.KingdomParkAveragesSummary.Length > 0) return true;

            return false;
        }

        public bool Report_GetActiveKingdomsSummary()
        {
            ReportService.GetActiveKingdomsSummaryRequest request = new ReportService.GetActiveKingdomsSummaryRequest() { KingdomAverageWeeks = 26, ParkAttendanceWithin = 4 };
            ReportService.GetActiveKingdomsSummaryResponse response = reportsvc.GetActiveKingdomsSummary(request);

            if (response.Status.Status == 0 && response.ActiveKingdomsSummaryList.Length > 0) return true;

            return false;
        }

        public bool Report_GetActivePlayers()
        {
            ReportService.GetActivePlayersRequest request = new ReportService.GetActivePlayersRequest() { MinimumAttendance = 1, MinimumCredits = 1 };
            ReportService.GetActivePlayersResponse response = reportsvc.GetActivePlayers(request);

            if (response.Status.Status == 0 && response.ActivePlayerSummary.Length > 0) return true;
            return false;
        }

    }

}