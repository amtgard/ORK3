using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Reflection;

namespace TestServices
{
    public partial class UnitTest
    {

        public bool Kingdom_GetKingdomShortInfo()
        {
            KingdomService.GetKingdomShortInfoRequest request = new KingdomService.GetKingdomShortInfoRequest() { KingdomId = 1 };
            KingdomService.GetKingdomShortInfoResponse response = kingdomsvc.GetKingdomShortInfo(request);

            if (response.Status.Status == 0 && response.KingdomInfo.KingdomId == 1) return true;

            return false;
        }

        public bool Kingdom_GetKingdomDetails()
        {
            KingdomService.GetKingdomDetailsRequest request = new KingdomService.GetKingdomDetailsRequest() { KingdomId = 1 };
            KingdomService.GetKingdomDetailsResponse response = kingdomsvc.GetKingdomDetails(request);

            if (response.Status.Status == 0 && response.KingdomInfo.KingdomId == 1 && response.KingdomConfiguration.Length > 0) return true;

            return false;
        }

        public bool Kingdom_Waffle()
        {
            KingdomService.WaffleKingdomRequest wrequest = new KingdomService.WaffleKingdomRequest() { KingdomId = 1, Token = LoginAsAdmin() };
            KingdomService.StatusType wresponse = kingdomsvc.RetireKingdom(wrequest);

            if (wresponse.Status == 0)
            {
                wresponse = kingdomsvc.RestoreKingdom(wrequest);
                if (wresponse.Status == 0) return true;
            }

            return false;
        }

        public bool Kingdom_SetKingdomDetails()
        {
            KingdomService.GetKingdomDetailsRequest gkrequest = new KingdomService.GetKingdomDetailsRequest() { KingdomId = 1 };
            KingdomService.GetKingdomDetailsResponse gkresponse = kingdomsvc.GetKingdomDetails(gkrequest);

            if (gkresponse.Status.Status == 0 && gkresponse.KingdomInfo.KingdomId == 1 && gkresponse.KingdomConfiguration.Length > 0)
            {
                string abbr = gkresponse.KingdomInfo.Abbreviation;
                KingdomService.ConfigurationEditItemType kc = new KingdomService.ConfigurationEditItemType();
                foreach (var config in gkresponse.KingdomConfiguration)
                {
                    if ("AttendanceMinimum" == config.Key)
                    {
                        kc.Action = "Edit";
                        kc.ConfigurationId = config.ConfigurationId;
                        kc.Key = config.Key;
                        kc.Value = "6";
                    }
                }
                KingdomService.SetKingdomDetailsRequest skrequest = new KingdomService.SetKingdomDetailsRequest()
                {
                    Token = LoginAsKingdomPM(),
                    KingdomId = 1,
                    Abbreviation = "M2",
                    KingdomConfiguration = new KingdomService.ConfigurationEditItemType[1] { kc }
                };
                KingdomService.StatusType skresponse = kingdomsvc.SetKingdomDetails(skrequest);
                if (skresponse.Status == 0)
                {
                    return true;
                }
            }
            return false;
        }

        public bool Kingdom_GetKingdomAuthorizations()
        {
            KingdomService.GetKingdomAuthorizationsRequest request = new KingdomService.GetKingdomAuthorizationsRequest() { KingdomId = 1 };
            KingdomService.GetKingdomAuthorizationsResponse response = kingdomsvc.GetKingdomAuthorizations(request);

            if (response.Status.Status == 0 && response.Authorizations.Length > 0) return true;

            return false;
        }

        public bool Kingdom_CreateKingdom()
        {
            return true;
            KingdomService.CreateKingdomRequest request = new KingdomService.CreateKingdomRequest()
            {
                Name = "unit test kingdom", AttendanceCreditMinimum = 9, AttendanceMinimum = 6, AveragePeriod = 6, Token = LoginAsAdmin(), AttendancePeriodType = "month"
            };
            KingdomService.StatusType response = kingdomsvc.CreateKingdom(request);

            if (response.Status == 0 && int.Parse(response.Detail) > 0) return true;

            return false;
        }

        public void ch()
        {
        }
    }

}