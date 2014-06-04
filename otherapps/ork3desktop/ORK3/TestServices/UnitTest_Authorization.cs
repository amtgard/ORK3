using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Reflection;

namespace TestServices
{
    public partial class UnitTest
    {
        public bool Authorization_AddAuthorization()
        {
            return false;
        }

        public bool Authorization_GetAuthorizations()
        {
            AuthorizationService.GetAuthorizationsRequest request = new AuthorizationService.GetAuthorizationsRequest() { Token = LoginAsAdmin(), MundaneId = 1 };
            AuthorizationService.GetAuthorizationsResponse response = authsvc.GetAuthorizations(request);
            if (response.Authorizations.Length > 0)
            {
                return true;
            }
            return false;
        }

        public bool Authorization_ResetPassword()
        {
            AuthorizationService.ResetPasswordRequest request = new AuthorizationService.ResetPasswordRequest() { Email = "en.gannim+ork3@gmail.com", UserName = "password" };
            AuthorizationService.StatusType s = authsvc.ResetPassword(request);
            if (s.Status == 0) return true;
            return false;
        }

        public bool Authorization_Authorize()
        {
            AuthorizationService.AuthorizeRequest authreq = new AuthorizationService.AuthorizeRequest();
            authreq.UserName = "admin";
            authreq.Password = "p455w0rd";
            AuthorizationService.AuthorizeResponse authresp = authsvc.Authorize(authreq);

            if (authresp.Status.Status != 0)
            {
                return false;
            }

            authreq.Token = authresp.Token;

            authresp = authsvc.Authorize(authreq);

            if (authresp.Status.Status != 0 || authresp.Token == authreq.Token)
            {
                return false;
            }


            return true;
        }

        public bool Authorization_XSiteAuthorize()
        {
            AuthorizationService.XSiteAuthorizeRequest authreq = new AuthorizationService.XSiteAuthorizeRequest();
            authreq.UserName = "admin";
            authreq.Password = "p455w0rd";
            AuthorizationService.XSiteAuthorizeResponse authresp = authsvc.XSiteAuthorize(authreq);

            if (authresp.Status.Status != 0)
            {
                return false;
            }

            return true;
        }

    }

}