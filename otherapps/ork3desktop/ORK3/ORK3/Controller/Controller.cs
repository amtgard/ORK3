using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;

namespace ORK3.Controller
{
    public partial class Controller
    {
        private static AuthorizationService.AuthorizationService AuthSvc;

        private static string Token;

        private static Dictionary<DateTime, AuthorizationService.StatusType> _Errors;

        public Dictionary<DateTime, AuthorizationService.StatusType> Errors
        {
            get
            {
                return _Errors;
            }
        }

        public Controller()
        {
            AuthSvc = new AuthorizationService.AuthorizationService();
            AuthSvc.Url = Model.Configuration.Config["ServiceURL"];
            _Errors = new Dictionary<DateTime, AuthorizationService.StatusType>();
            try
            {
                Login(Model.Configuration.Config["UserName"], Model.Configuration.Config["Password"]);
            }
            catch (Exception LoginExc)
            {
                System.Windows.Forms.MessageBox.Show("Your User Name or Password are incorrect.");
            }
        }

        public void Login(string UserName, string Password)
        {
            AuthorizationService.AuthorizeRequest aRequest = new AuthorizationService.AuthorizeRequest() { UserName = UserName, Password = Password };
            AuthorizationService.AuthorizeResponse aResponse = AuthSvc.Authorize(aRequest);

            if (aResponse.Status.Status != 0)
            {
                throw new Exception(aResponse.Status.Error + "\n\n" + aResponse.Status.Detail);
            }
            Token = aResponse.Token;
        }

        private void AddError(AuthorizationService.StatusType Status)
        {
            _Errors.Add(DateTime.Now, Status);
        }
    }
}
